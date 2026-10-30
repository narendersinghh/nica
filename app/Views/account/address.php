<?php $states = config('us_states');
echo implode('<br>', array_map('e', array_filter([
    trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')), $a['address_1'] ?? '', $a['address_2'] ?? '',
    trim(($a['city'] ?? '') . ', ' . ($states[$a['state'] ?? ''] ?? ($a['state'] ?? '')) . ' ' . ($a['postcode'] ?? ''), ', '),
    ($a['phone'] ?? '') ?: null,
])));
