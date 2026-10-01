<?php

return [
    // true  => di Komunitas Umum, admin hanya boleh mengubah/menghapus pembelajaran buatannya sendiri.
    // false => semua admin komunitas boleh mengelola semua pembelajaran di Komunitas Umum.
    'umum_hanya_pembuat' => env('KOMUNITAS_UMUM_HANYA_PEMBUAT', false),
];
