<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Mail\Mailer;

final class ContactController extends Controller
{
    public function submit(Request $request): Response
    {
        // Bots fill the hidden field: pretend success, store nothing.
        if ($request->string('website') !== '') {
            $this->app->session()->flash('contact_sent', true);
            return $this->redirect('/contact-us/#contact-form', 303);
        }

        $data = $this->validate($request, [
            'role' => 'required|in:vendor,stylist,customer',
            'name' => 'required|string|max:191',
            'email' => 'required|email',
            'phone' => 'required|string|max:40|regex:/^[0-9 +().-]{6,40}$/',
            'message' => 'required|string|min:5|max:5000',
        ]);

        $this->app->db()->insert('contact_messages', [
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'phone' => $data['phone'],
            'subject' => ucfirst($data['role']) . ' enquiry',
            'message' => $data['message'],
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $to = (string) \App\Services\Settings::get('site.contact.email');
        (new Mailer())->queue($to, 'New ' . $data['role'] . ' enquiry from ' . $data['name'], $this->app->view()->render('emails/contact', ['data' => $data]));

        $this->app->session()->flash('contact_sent', true);
        return $this->redirect('/contact-us/#contact-form', 303);
    }
}
