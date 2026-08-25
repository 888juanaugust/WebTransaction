<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pesan validasi / Validation messages
|--------------------------------------------------------------------------
|
| APP_LOCALE and APP_FALLBACK_LOCALE are both `id`, so nothing falls back to
| Laravel's built-in English file. Without this file every validation message
| in the application renders as its own translation key — a staff member
| entering a duplicate email was shown the literal string "validation.unique".
|
| Most rules never surface, because the browser's own required/type checks
| fire before the form is submitted. The ones that always reach the server are
| the ones that cannot be checked in the browser at all: `unique`, `exists`,
| and anything custom. Those were the visible half of the bug.
|
| Every message that would otherwise open with `:attribute` opens with "Kolom"
| instead. Laravel snake-cases the attribute before substituting it, so a
| leading `:attribute` starts the sentence in lower case — "email sudah
| dipakai." — which reads like a truncated string rather than a sentence. The
| fixed first word also keeps the whole file consistent, instead of some
| messages being capitalised and others not depending on the field name.
|
*/

return [

    'accepted' => 'Kolom :attribute harus disetujui.',
    'accepted_if' => 'Kolom :attribute harus disetujui bila :other bernilai :value.',
    'active_url' => 'Kolom :attribute bukan URL yang sah.',
    'after' => 'Kolom :attribute harus tanggal setelah :date.',
    'after_or_equal' => 'Kolom :attribute harus tanggal setelah atau sama dengan :date.',
    'alpha' => 'Kolom :attribute hanya boleh berisi huruf.',
    'alpha_dash' => 'Kolom :attribute hanya boleh berisi huruf, angka, tanda hubung, dan garis bawah.',
    'alpha_num' => 'Kolom :attribute hanya boleh berisi huruf dan angka.',
    'array' => 'Kolom :attribute harus berupa daftar.',
    'ascii' => 'Kolom :attribute hanya boleh berisi karakter dan simbol satu byte.',
    'before' => 'Kolom :attribute harus tanggal sebelum :date.',
    'before_or_equal' => 'Kolom :attribute harus tanggal sebelum atau sama dengan :date.',

    'between' => [
        'array' => 'Kolom :attribute harus berisi antara :min sampai :max item.',
        'file' => 'Ukuran :attribute harus antara :min sampai :max kilobyte.',
        'numeric' => 'Kolom :attribute harus antara :min sampai :max.',
        'string' => 'Kolom :attribute harus antara :min sampai :max karakter.',
    ],

    'boolean' => 'Kolom :attribute harus bernilai ya atau tidak.',
    'can' => 'Kolom :attribute berisi nilai yang tidak diizinkan.',
    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'contains' => 'Kolom :attribute belum memuat nilai yang diperlukan.',
    'current_password' => 'Sandi salah.',
    'date' => 'Kolom :attribute bukan tanggal yang sah.',
    'date_equals' => 'Kolom :attribute harus tanggal yang sama dengan :date.',
    'date_format' => 'Kolom :attribute tidak sesuai format :format.',
    'decimal' => 'Kolom :attribute harus memiliki :decimal angka di belakang koma.',
    'declined' => 'Kolom :attribute harus ditolak.',
    'declined_if' => 'Kolom :attribute harus ditolak bila :other bernilai :value.',
    'different' => 'Kolom :attribute dan :other harus berbeda.',
    'digits' => 'Kolom :attribute harus terdiri dari :digits angka.',
    'digits_between' => 'Kolom :attribute harus terdiri dari :min sampai :max angka.',
    'dimensions' => 'Ukuran gambar :attribute tidak sesuai.',
    'distinct' => 'Kolom :attribute berisi nilai yang sama dua kali.',
    'doesnt_end_with' => 'Kolom :attribute tidak boleh diakhiri dengan salah satu dari: :values.',
    'doesnt_start_with' => 'Kolom :attribute tidak boleh diawali dengan salah satu dari: :values.',
    'email' => 'Kolom :attribute harus berupa alamat email yang sah.',
    'ends_with' => 'Kolom :attribute harus diakhiri dengan salah satu dari: :values.',
    'enum' => 'Kolom :attribute berisi pilihan yang tidak sah.',
    'exists' => 'Kolom :attribute yang dipilih tidak ada.',
    'extensions' => 'Kolom :attribute harus berekstensi salah satu dari: :values.',
    'file' => 'Kolom :attribute harus berupa berkas.',
    'filled' => 'Kolom :attribute harus diisi.',

    'gt' => [
        'array' => 'Kolom :attribute harus berisi lebih dari :value item.',
        'file' => 'Ukuran :attribute harus lebih dari :value kilobyte.',
        'numeric' => 'Kolom :attribute harus lebih besar dari :value.',
        'string' => 'Kolom :attribute harus lebih dari :value karakter.',
    ],

    'gte' => [
        'array' => 'Kolom :attribute harus berisi :value item atau lebih.',
        'file' => 'Ukuran :attribute harus :value kilobyte atau lebih.',
        'numeric' => 'Kolom :attribute harus :value atau lebih.',
        'string' => 'Kolom :attribute harus :value karakter atau lebih.',
    ],

    'hex_color' => 'Kolom :attribute harus berupa kode warna heksadesimal yang sah.',
    'image' => 'Kolom :attribute harus berupa gambar.',
    'in' => 'Kolom :attribute berisi pilihan yang tidak sah.',
    'in_array' => 'Kolom :attribute tidak ada di dalam :other.',
    'integer' => 'Kolom :attribute harus berupa bilangan bulat.',
    'ip' => 'Kolom :attribute harus berupa alamat IP yang sah.',
    'ipv4' => 'Kolom :attribute harus berupa alamat IPv4 yang sah.',
    'ipv6' => 'Kolom :attribute harus berupa alamat IPv6 yang sah.',
    'json' => 'Kolom :attribute harus berupa JSON yang sah.',
    'list' => 'Kolom :attribute harus berupa daftar.',
    'lowercase' => 'Kolom :attribute harus huruf kecil semua.',

    'lt' => [
        'array' => 'Kolom :attribute harus berisi kurang dari :value item.',
        'file' => 'Ukuran :attribute harus kurang dari :value kilobyte.',
        'numeric' => 'Kolom :attribute harus lebih kecil dari :value.',
        'string' => 'Kolom :attribute harus kurang dari :value karakter.',
    ],

    'lte' => [
        'array' => 'Kolom :attribute tidak boleh berisi lebih dari :value item.',
        'file' => 'Ukuran :attribute tidak boleh lebih dari :value kilobyte.',
        'numeric' => 'Kolom :attribute tidak boleh lebih dari :value.',
        'string' => 'Kolom :attribute tidak boleh lebih dari :value karakter.',
    ],

    'mac_address' => 'Kolom :attribute harus berupa alamat MAC yang sah.',

    'max' => [
        'array' => 'Kolom :attribute tidak boleh berisi lebih dari :max item.',
        'file' => 'Ukuran :attribute tidak boleh lebih dari :max kilobyte.',
        'numeric' => 'Kolom :attribute tidak boleh lebih dari :max.',
        'string' => 'Kolom :attribute tidak boleh lebih dari :max karakter.',
    ],

    'max_digits' => 'Kolom :attribute tidak boleh lebih dari :max angka.',
    'mimes' => 'Kolom :attribute harus berupa berkas berjenis: :values.',
    'mimetypes' => 'Kolom :attribute harus berupa berkas berjenis: :values.',

    'min' => [
        'array' => 'Kolom :attribute harus berisi minimal :min item.',
        'file' => 'Ukuran :attribute minimal :min kilobyte.',
        'numeric' => 'Kolom :attribute minimal :min.',
        'string' => 'Kolom :attribute minimal :min karakter.',
    ],

    'min_digits' => 'Kolom :attribute minimal :min angka.',
    'missing' => 'Kolom :attribute tidak boleh ada.',
    'missing_if' => 'Kolom :attribute tidak boleh ada bila :other bernilai :value.',
    'missing_unless' => 'Kolom :attribute tidak boleh ada kecuali :other bernilai :value.',
    'missing_with' => 'Kolom :attribute tidak boleh ada bila :values ada.',
    'missing_with_all' => 'Kolom :attribute tidak boleh ada bila :values ada.',
    'multiple_of' => 'Kolom :attribute harus kelipatan dari :value.',
    'not_in' => 'Kolom :attribute berisi pilihan yang tidak sah.',
    'not_regex' => 'Format :attribute tidak sah.',
    'numeric' => 'Kolom :attribute harus berupa angka.',

    'password' => [
        'letters' => 'Sandi harus mengandung minimal satu huruf.',
        'mixed' => 'Sandi harus mengandung huruf besar dan huruf kecil.',
        'numbers' => 'Sandi harus mengandung minimal satu angka.',
        'symbols' => 'Sandi harus mengandung minimal satu simbol.',
        'uncompromised' => 'Sandi ini pernah bocor di kebocoran data. Pakai sandi lain.',
    ],

    'present' => 'Kolom :attribute harus ada.',
    'present_if' => 'Kolom :attribute harus ada bila :other bernilai :value.',
    'present_unless' => 'Kolom :attribute harus ada kecuali :other bernilai :value.',
    'present_with' => 'Kolom :attribute harus ada bila :values ada.',
    'present_with_all' => 'Kolom :attribute harus ada bila :values ada.',
    'prohibited' => 'Kolom :attribute tidak boleh diisi.',
    'prohibited_if' => 'Kolom :attribute tidak boleh diisi bila :other bernilai :value.',
    'prohibited_if_accepted' => 'Kolom :attribute tidak boleh diisi bila :other disetujui.',
    'prohibited_if_declined' => 'Kolom :attribute tidak boleh diisi bila :other ditolak.',
    'prohibited_unless' => 'Kolom :attribute tidak boleh diisi kecuali :other ada di :values.',
    'prohibits' => 'Kolom :attribute membuat :other tidak boleh diisi.',
    'regex' => 'Format :attribute tidak sah.',
    'required' => 'Kolom :attribute wajib diisi.',
    'required_array_keys' => 'Kolom :attribute harus memuat: :values.',
    'required_if' => 'Kolom :attribute wajib diisi bila :other bernilai :value.',
    'required_if_accepted' => 'Kolom :attribute wajib diisi bila :other disetujui.',
    'required_if_declined' => 'Kolom :attribute wajib diisi bila :other ditolak.',
    'required_unless' => 'Kolom :attribute wajib diisi kecuali :other ada di :values.',
    'required_with' => 'Kolom :attribute wajib diisi bila :values ada.',
    'required_with_all' => 'Kolom :attribute wajib diisi bila :values ada.',
    'required_without' => 'Kolom :attribute wajib diisi bila :values tidak ada.',
    'required_without_all' => 'Kolom :attribute wajib diisi bila :values tidak ada satu pun.',
    'same' => 'Kolom :attribute dan :other harus sama.',

    'size' => [
        'array' => 'Kolom :attribute harus berisi :size item.',
        'file' => 'Ukuran :attribute harus :size kilobyte.',
        'numeric' => 'Kolom :attribute harus bernilai :size.',
        'string' => 'Kolom :attribute harus :size karakter.',
    ],

    'starts_with' => 'Kolom :attribute harus diawali dengan salah satu dari: :values.',
    'string' => 'Kolom :attribute harus berupa teks.',
    'timezone' => 'Kolom :attribute harus berupa zona waktu yang sah.',
    'unique' => 'Kolom :attribute sudah dipakai.',
    'uploaded' => 'Kolom :attribute gagal diunggah.',
    'uppercase' => 'Kolom :attribute harus huruf besar semua.',
    'url' => 'Kolom :attribute harus berupa URL yang sah.',
    'ulid' => 'Kolom :attribute harus berupa ULID yang sah.',
    'uuid' => 'Kolom :attribute harus berupa UUID yang sah.',

    /*
    |--------------------------------------------------------------------------
    | Pesan khusus per kolom
    |--------------------------------------------------------------------------
    |
    | Kosong dengan sengaja. Pesan di atas sudah menyebut nama kolomnya, dan
    | satu pesan khusus di sini akan menjadi tempat kedua sebuah aturan
    | dituliskan.
    |
    */

    'custom' => [],

    'attributes' => [],

];
