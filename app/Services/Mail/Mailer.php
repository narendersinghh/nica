<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Core\Env;

/**
 * Emails go into email_queue and are delivered by `bin/console queue:work`
 * (cron) so a slow mail server never slows a page. With MAIL_DRIVER=log the
 * message is written to storage/mail/ immediately instead (local testing).
 */
final class Mailer
{
    public function queue(string $to, string $subject, string $html, ?string $text = null): void
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $subject = str_replace(["\r", "\n"], ' ', $subject);
        $id = app()->db()->insert('email_queue', [
            'to_email' => $to,
            'subject' => mb_substr_safe($subject, 255),
            'html' => $html,
            'text' => $text ?? trim(html_entity_decode(strip_tags(preg_replace('#<(br|/p|/h\d|/li|/tr)>#i', "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
        ]);
        if ($this->driver() === 'log') {
            $this->deliver($id);
        }
    }

    /** Send up to $limit due messages; returns [sent, failed]. */
    public function work(int $limit = 50): array
    {
        $db = app()->db();
        $sent = $failed = 0;
        foreach ($db->select("SELECT id FROM email_queue WHERE status = 'pending' AND send_after <= UTC_TIMESTAMP() ORDER BY id LIMIT " . max(1, $limit)) as $row) {
            $this->deliver((int) $row['id']) ? $sent++ : $failed++;
        }
        return [$sent, $failed];
    }

    private function deliver(int $id): bool
    {
        $db = app()->db();
        $mail = $db->first('SELECT * FROM email_queue WHERE id = ?', [$id]);
        if ($mail === null || $mail['status'] !== 'pending') {
            return false;
        }
        try {
            $this->driver() === 'log' ? $this->writeLog($mail) : $this->smtp($mail);
            // The body can hold live links (password reset, welcome): don't keep it once delivered.
            $db->update('email_queue', ['status' => 'sent', 'sent_at' => gmdate('Y-m-d H:i:s'), 'attempts' => $mail['attempts'] + 1, 'html' => '', 'text' => null], ['id' => $id]);
            return true;
        } catch (\Throwable $e) {
            $attempts = (int) $mail['attempts'] + 1;
            $db->update('email_queue', [
                'attempts' => $attempts,
                'status' => $attempts >= 5 ? 'failed' : 'pending',
                'last_error' => mb_substr_safe($e->getMessage(), 500),
                // Back off: 1, 4, 9, 16 minutes.
                'send_after' => gmdate('Y-m-d H:i:s', time() + 60 * $attempts * $attempts),
            ], ['id' => $id]);
            app()->logger()->warning('Mail delivery failed', ['id' => $id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private function driver(): string
    {
        return strtolower((string) Env::get('MAIL_DRIVER', 'log'));
    }

    private function from(): array
    {
        $address = (string) Env::get('MAIL_FROM_ADDRESS', '');
        if ($address === '') {
            $address = 'no-reply@' . preg_replace('/^www\./', '', app()->url()->hostName());
        }
        return [$address, (string) Env::get('MAIL_FROM_NAME', config('app.name'))];
    }

    private function writeLog(array $mail): void
    {
        $dir = app()->path('storage/mail');
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        [$fromAddress, $fromName] = $this->from();
        $file = sprintf('%s/%s-%d.html', $dir, gmdate('Ymd-His'), $mail['id']);
        $header = sprintf("<!--\nFrom: %s <%s>\nTo: %s\nSubject: %s\n-->\n", $fromName, $fromAddress, $mail['to_email'], $mail['subject']);
        file_put_contents($file, $header . $mail['html']);
    }

    /** Minimal SMTP client: STARTTLS or implicit TLS, AUTH LOGIN, multipart/alternative. */
    private function smtp(array $mail): void
    {
        $host = (string) Env::get('MAIL_HOST', '');
        $port = (int) Env::get('MAIL_PORT', 587);
        $encryption = strtolower((string) Env::get('MAIL_ENCRYPTION', 'tls'));
        if ($host === '') {
            throw new \RuntimeException('MAIL_HOST is not configured.');
        }
        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $socket = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            throw new \RuntimeException("SMTP connect failed: {$errstr}");
        }
        stream_set_timeout($socket, 20);

        $read = static function () use ($socket): string {
            $data = '';
            while (($line = fgets($socket, 515)) !== false) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $cmd = static function (string $command, array $expect) use ($socket, $read): string {
            if ($command !== '') {
                fwrite($socket, $command . "\r\n");
            }
            $reply = $read();
            if (!in_array((int) substr($reply, 0, 3), $expect, true)) {
                throw new \RuntimeException('SMTP error: ' . trim(preg_replace('/AUTH.*$/s', '', $reply)));
            }
            return $reply;
        };

        $ehloHost = preg_replace('/[^a-z0-9.-]/i', '', app()->url()->hostName()) ?: 'localhost';
        $cmd('', [220]);
        $cmd('EHLO ' . $ehloHost, [250]);
        if ($encryption === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new \RuntimeException('STARTTLS failed.');
            }
            $cmd('EHLO ' . $ehloHost, [250]);
        }
        $user = (string) Env::get('MAIL_USERNAME', '');
        if ($user !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($user), [334]);
            $cmd(base64_encode((string) Env::get('MAIL_PASSWORD', '')), [235]);
        }
        [$fromAddress, $fromName] = $this->from();
        $cmd('MAIL FROM:<' . $fromAddress . '>', [250]);
        $cmd('RCPT TO:<' . $mail['to_email'] . '>', [250, 251]);
        $cmd('DATA', [354]);

        $boundary = 'b' . bin2hex(random_bytes(12));
        $encodeHeader = static fn (string $s) => preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $encodeHeader($fromName) . ' <' . $fromAddress . '>',
            'To: <' . $mail['to_email'] . '>',
            'Subject: ' . $encodeHeader($mail['subject']),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $ehloHost . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode((string) $mail['text']))
            . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode((string) $mail['html']))
            . "--{$boundary}--";
        $cmd(implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.", [250]);
        $cmd('QUIT', [221]);
        fclose($socket);
    }
}
