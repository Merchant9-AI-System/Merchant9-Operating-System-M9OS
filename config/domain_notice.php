<?php

// Modal "domain telah bertukar" di panel admin (rujuk App\Support\DomainNotice & AdminPanelProvider::renderHook).
// Hidupkan melalui .env sahaja - tiada perubahan kod:
//   DOMAIN_NOTICE_ENABLED=true
//   DOMAIN_NOTICE_NEW_URL=https://domain-baharu.example.com
//   DOMAIN_NOTICE_UNTIL=2026-12-31   (pilihan - lepas tarikh ini modal berhenti dipaparkan)
return [
    'enabled' => (bool) env('DOMAIN_NOTICE_ENABLED', false),

    // URL penuh domain BAHARU (dengan https://). Modal tak dipaparkan bila hos semasa sudah sama dgn ini.
    'new_url' => env('DOMAIN_NOTICE_NEW_URL'),

    // Pilihan: tarikh akhir (Y-m-d) paparan modal, termasuk hari itu.
    'until' => env('DOMAIN_NOTICE_UNTIL'),
];
