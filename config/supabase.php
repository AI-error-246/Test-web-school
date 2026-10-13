<?php

return [
    'url' => env('SUPABASE_URL'),
    'publishable_key' => env('SUPABASE_PUBLISHABLE_KEY'),
    'jwks_url' => env('SUPABASE_JWKS_URL'),
    'secret_key' => env('SUPABASE_SECRET_KEY'),
    'cv_disk' => env('SUPABASE_CV_DISK', 'local'),
    'storage_bucket' => env('SUPABASE_STORAGE_BUCKET', 'internship-cvs'),
];
