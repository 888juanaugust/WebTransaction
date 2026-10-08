<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => 'Isian :attribute harus diterima.',
    'accepted_if' => 'Isian :attribute harus diterima jika :other bernilai :value.',
    'active_url' => 'Isian :attribute harus berupa URL yang valid.',
    'after' => 'Isian :attribute harus berupa tanggal setelah :date.',
    'after_or_equal' => 'Isian :attribute harus berupa tanggal setelah atau sama dengan :date.',
    'alpha' => 'Isian :attribute hanya boleh berisi huruf.',
    'alpha_dash' => 'Isian :attribute hanya boleh berisi huruf, angka, tanda hubung, dan garis bawah.',
    'alpha_num' => 'Isian :attribute hanya boleh berisi huruf dan angka.',
    'any_of' => 'Isian :attribute tidak valid.',
    'array' => 'Isian :attribute harus berupa array.',
    'array_keys' => 'Isian :attribute hanya boleh berisi kunci berikut: :values.',
    'ascii' => 'Isian :attribute hanya boleh berisi karakter alfanumerik dan simbol satu bita.',
    'base64' => 'Isian :attribute harus berupa teks Base64 yang valid.',
    'before' => 'Isian :attribute harus berupa tanggal sebelum :date.',
    'before_or_equal' => 'Isian :attribute harus berupa tanggal sebelum atau sama dengan :date.',
    'between' => [
        'array' => 'Isian :attribute harus memiliki :min sampai :max elemen.',
        'file' => 'Isian :attribute harus berukuran antara :min dan :max kilobita.',
        'numeric' => 'Isian :attribute harus bernilai antara :min dan :max.',
        'string' => 'Isian :attribute harus berisi antara :min dan :max karakter.',
    ],
    'boolean' => 'Isian :attribute harus bernilai benar atau salah.',
    'can' => 'Isian :attribute berisi nilai yang tidak diizinkan.',
    'confirmed' => 'Konfirmasi isian :attribute tidak cocok.',
    'contains' => 'Isian :attribute tidak memuat nilai yang wajib ada.',
    'current_password' => 'Kata sandi salah.',
    'date' => 'Isian :attribute harus berupa tanggal yang valid.',
    'date_equals' => 'Isian :attribute harus berupa tanggal yang sama dengan :date.',
    'date_format' => 'Isian :attribute harus sesuai dengan format :format.',
    'decimal' => 'Isian :attribute harus memiliki :decimal angka desimal.',
    'declined' => 'Isian :attribute harus ditolak.',
    'declined_if' => 'Isian :attribute harus ditolak jika :other bernilai :value.',
    'different' => 'Isian :attribute dan :other harus berbeda.',
    'digits' => 'Isian :attribute harus terdiri atas :digits digit.',
    'digits_between' => 'Isian :attribute harus terdiri atas :min sampai :max digit.',
    'dimensions' => 'Isian :attribute memiliki dimensi gambar yang tidak valid.',
    'distinct' => 'Isian :attribute memiliki nilai duplikat.',
    'doesnt_contain' => 'Isian :attribute tidak boleh memuat salah satu dari berikut: :values.',
    'doesnt_end_with' => 'Isian :attribute tidak boleh diakhiri dengan salah satu dari berikut: :values.',
    'doesnt_start_with' => 'Isian :attribute tidak boleh diawali dengan salah satu dari berikut: :values.',
    'email' => 'Isian :attribute harus berupa alamat email yang valid.',
    'encoding' => 'Isian :attribute harus menggunakan pengodean :encoding.',
    'ends_with' => 'Isian :attribute harus diakhiri dengan salah satu dari berikut: :values.',
    'enum' => 'Pilihan :attribute tidak valid.',
    'exists' => 'Pilihan :attribute tidak valid.',
    'extensions' => 'Isian :attribute harus memiliki salah satu ekstensi berikut: :values.',
    'file' => 'Isian :attribute harus berupa berkas.',
    'filled' => 'Isian :attribute harus memiliki nilai.',
    'gt' => [
        'array' => 'Isian :attribute harus memiliki lebih dari :value elemen.',
        'file' => 'Isian :attribute harus berukuran lebih dari :value kilobita.',
        'numeric' => 'Isian :attribute harus lebih dari :value.',
        'string' => 'Isian :attribute harus berisi lebih dari :value karakter.',
    ],
    'gte' => [
        'array' => 'Isian :attribute harus memiliki :value elemen atau lebih.',
        'file' => 'Isian :attribute harus berukuran lebih dari atau sama dengan :value kilobita.',
        'numeric' => 'Isian :attribute harus lebih dari atau sama dengan :value.',
        'string' => 'Isian :attribute harus berisi :value karakter atau lebih.',
    ],
    'hex_color' => 'Isian :attribute harus berupa warna heksadesimal yang valid.',
    'image' => 'Isian :attribute harus berupa gambar.',
    'in' => 'Pilihan :attribute tidak valid.',
    'in_array' => 'Isian :attribute harus ada di :other.',
    'in_array_keys' => 'Isian :attribute harus berisi paling sedikit satu dari kunci berikut: :values.',
    'integer' => 'Isian :attribute harus berupa bilangan bulat.',
    'ip' => 'Isian :attribute harus berupa alamat IP yang valid.',
    'ipv4' => 'Isian :attribute harus berupa alamat IPv4 yang valid.',
    'ipv6' => 'Isian :attribute harus berupa alamat IPv6 yang valid.',
    'json' => 'Isian :attribute harus berupa teks JSON yang valid.',
    'list' => 'Isian :attribute harus berupa daftar.',
    'lowercase' => 'Isian :attribute harus berupa huruf kecil.',
    'lt' => [
        'array' => 'Isian :attribute harus memiliki kurang dari :value elemen.',
        'file' => 'Isian :attribute harus berukuran kurang dari :value kilobita.',
        'numeric' => 'Isian :attribute harus kurang dari :value.',
        'string' => 'Isian :attribute harus berisi kurang dari :value karakter.',
    ],
    'lte' => [
        'array' => 'Isian :attribute tidak boleh memiliki lebih dari :value elemen.',
        'file' => 'Isian :attribute harus berukuran kurang dari atau sama dengan :value kilobita.',
        'numeric' => 'Isian :attribute harus kurang dari atau sama dengan :value.',
        'string' => 'Isian :attribute harus berisi :value karakter atau kurang.',
    ],
    'mac_address' => 'Isian :attribute harus berupa alamat MAC yang valid.',
    'max' => [
        'array' => 'Isian :attribute tidak boleh memiliki lebih dari :max elemen.',
        'file' => 'Isian :attribute tidak boleh berukuran lebih dari :max kilobita.',
        'numeric' => 'Isian :attribute tidak boleh lebih dari :max.',
        'string' => 'Isian :attribute tidak boleh lebih dari :max karakter.',
    ],
    'max_digits' => 'Isian :attribute tidak boleh lebih dari :max digit.',
    'mimes' => 'Isian :attribute harus berupa berkas berjenis: :values.',
    'mimetypes' => 'Isian :attribute harus berupa berkas berjenis: :values.',
    'min' => [
        'array' => 'Isian :attribute harus memiliki paling sedikit :min elemen.',
        'file' => 'Isian :attribute harus berukuran paling sedikit :min kilobita.',
        'numeric' => 'Isian :attribute harus paling sedikit :min.',
        'string' => 'Isian :attribute harus berisi paling sedikit :min karakter.',
    ],
    'min_digits' => 'Isian :attribute harus memiliki paling sedikit :min digit.',
    'missing' => 'Isian :attribute tidak boleh ada.',
    'missing_if' => 'Isian :attribute tidak boleh ada jika :other bernilai :value.',
    'missing_unless' => 'Isian :attribute tidak boleh ada kecuali :other bernilai :value.',
    'missing_with' => 'Isian :attribute tidak boleh ada jika :values ada.',
    'missing_with_all' => 'Isian :attribute tidak boleh ada jika semua :values ada.',
    'multiple_of' => 'Isian :attribute harus kelipatan :value.',
    'not_in' => 'Pilihan :attribute tidak valid.',
    'not_regex' => 'Format isian :attribute tidak valid.',
    'numeric' => 'Isian :attribute harus berupa angka.',
    'password' => [
        'letters' => 'Isian :attribute harus berisi paling sedikit satu huruf.',
        'mixed' => 'Isian :attribute harus berisi paling sedikit satu huruf kapital dan satu huruf kecil.',
        'numbers' => 'Isian :attribute harus berisi paling sedikit satu angka.',
        'symbols' => 'Isian :attribute harus berisi paling sedikit satu simbol.',
        'uncompromised' => 'Isian :attribute yang diberikan pernah muncul dalam kebocoran data. Silakan pilih :attribute lain.',
    ],
    'present' => 'Isian :attribute harus ada.',
    'present_if' => 'Isian :attribute harus ada jika :other bernilai :value.',
    'present_unless' => 'Isian :attribute harus ada kecuali :other bernilai :value.',
    'present_with' => 'Isian :attribute harus ada jika :values ada.',
    'present_with_all' => 'Isian :attribute harus ada jika semua :values ada.',
    'prohibited' => 'Isian :attribute tidak boleh diisi.',
    'prohibited_if' => 'Isian :attribute tidak boleh diisi jika :other bernilai :value.',
    'prohibited_if_accepted' => 'Isian :attribute tidak boleh diisi jika :other diterima.',
    'prohibited_if_declined' => 'Isian :attribute tidak boleh diisi jika :other ditolak.',
    'prohibited_unless' => 'Isian :attribute tidak boleh diisi kecuali :other termasuk dalam :values.',
    'prohibits' => 'Isian :attribute melarang :other untuk diisi.',
    'regex' => 'Format isian :attribute tidak valid.',
    'required' => 'Isian :attribute wajib diisi.',
    'required_array_keys' => 'Isian :attribute harus berisi entri untuk: :values.',
    'required_if' => 'Isian :attribute wajib diisi jika :other bernilai :value.',
    'required_if_accepted' => 'Isian :attribute wajib diisi jika :other diterima.',
    'required_if_declined' => 'Isian :attribute wajib diisi jika :other ditolak.',
    'required_unless' => 'Isian :attribute wajib diisi kecuali :other termasuk dalam :values.',
    'required_with' => 'Isian :attribute wajib diisi jika :values ada.',
    'required_with_all' => 'Isian :attribute wajib diisi jika semua :values ada.',
    'required_without' => 'Isian :attribute wajib diisi jika :values tidak ada.',
    'required_without_all' => 'Isian :attribute wajib diisi jika tidak ada satu pun dari :values.',
    'same' => 'Isian :attribute harus sama dengan :other.',
    'size' => [
        'array' => 'Isian :attribute harus berisi :size elemen.',
        'file' => 'Isian :attribute harus berukuran :size kilobita.',
        'numeric' => 'Isian :attribute harus bernilai :size.',
        'string' => 'Isian :attribute harus berisi :size karakter.',
    ],
    'starts_with' => 'Isian :attribute harus diawali dengan salah satu dari berikut: :values.',
    'string' => 'Isian :attribute harus berupa teks.',
    'timezone' => 'Isian :attribute harus berupa zona waktu yang valid.',
    'unique' => 'Isian :attribute sudah digunakan.',
    'uploaded' => 'Isian :attribute gagal diunggah.',
    'uppercase' => 'Isian :attribute harus berupa huruf kapital.',
    'url' => 'Isian :attribute harus berupa URL yang valid.',
    'ulid' => 'Isian :attribute harus berupa ULID yang valid.',
    'uuid' => 'Isian :attribute harus berupa UUID yang valid.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    'attributes' => [],

];
