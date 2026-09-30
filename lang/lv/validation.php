<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Latvian versions of the framework's default validation messages. English
    | ones come from the framework itself (app locale fallback).
    |
    */

    'accepted' => 'Laukam :attribute jābūt apstiprinātam.',
    'accepted_if' => 'Laukam :attribute jābūt apstiprinātam, ja :other ir :value.',
    'active_url' => 'Laukā :attribute jābūt derīgam URL.',
    'after' => 'Laukā :attribute jābūt datumam pēc :date.',
    'after_or_equal' => 'Laukā :attribute jābūt datumam, kas ir :date vai vēlāk.',
    'alpha' => 'Laukā :attribute drīkst būt tikai burti.',
    'alpha_dash' => 'Laukā :attribute drīkst būt tikai burti, cipari, domuzīmes un pasvītras.',
    'alpha_num' => 'Laukā :attribute drīkst būt tikai burti un cipari.',
    'any_of' => 'Lauks :attribute nav derīgs.',
    'array' => 'Laukam :attribute jābūt masīvam.',
    'array_keys' => 'Laukā :attribute drīkst būt tikai šādas atslēgas: :values.',
    'ascii' => 'Laukā :attribute drīkst būt tikai viena baita burti, cipari un simboli.',
    'base64' => 'Laukā :attribute jābūt derīgai Base64 virknei.',
    'before' => 'Laukā :attribute jābūt datumam pirms :date.',
    'before_or_equal' => 'Laukā :attribute jābūt datumam, kas ir :date vai agrāk.',
    'between' => [
        'array' => 'Laukā :attribute jābūt no :min līdz :max vienībām.',
        'file' => 'Laukam :attribute jābūt no :min līdz :max kilobaitiem.',
        'numeric' => 'Laukam :attribute jābūt no :min līdz :max.',
        'string' => 'Laukā :attribute jābūt no :min līdz :max rakstzīmēm.',
    ],
    'boolean' => 'Laukam :attribute jābūt patiesam vai nepatiesam.',
    'can' => 'Laukā :attribute ir neatļauta vērtība.',
    'confirmed' => 'Lauka :attribute apstiprinājums nesakrīt.',
    'contains' => 'Laukā :attribute trūkst obligātas vērtības.',
    'current_password' => 'Parole nav pareiza.',
    'date' => 'Laukā :attribute jābūt derīgam datumam.',
    'date_equals' => 'Laukā :attribute jābūt datumam, kas ir :date.',
    'date_format' => 'Laukam :attribute jāatbilst formātam :format.',
    'decimal' => 'Laukā :attribute jābūt :decimal zīmēm aiz komata.',
    'declined' => 'Laukam :attribute jābūt noraidītam.',
    'declined_if' => 'Laukam :attribute jābūt noraidītam, ja :other ir :value.',
    'different' => 'Laukiem :attribute un :other jābūt atšķirīgiem.',
    'digits' => 'Laukā :attribute jābūt :digits cipariem.',
    'digits_between' => 'Laukā :attribute jābūt no :min līdz :max cipariem.',
    'dimensions' => 'Laukā :attribute ir nederīgi attēla izmēri.',
    'distinct' => 'Laukā :attribute ir dublēta vērtība.',
    'doesnt_contain' => 'Lauks :attribute nedrīkst saturēt nevienu no šiem: :values.',
    'doesnt_end_with' => 'Lauks :attribute nedrīkst beigties ar kādu no šiem: :values.',
    'doesnt_start_with' => 'Lauks :attribute nedrīkst sākties ar kādu no šiem: :values.',
    'email' => 'Laukā :attribute jābūt derīgai e-pasta adresei.',
    'encoding' => 'Laukam :attribute jābūt kodētam :encoding kodējumā.',
    'ends_with' => 'Laukam :attribute jābeidzas ar kādu no šiem: :values.',
    'enum' => 'Izvēlētā vērtība laukā :attribute nav derīga.',
    'exists' => 'Izvēlētā vērtība laukā :attribute nav derīga.',
    'extensions' => 'Laukam :attribute jābūt ar kādu no šiem paplašinājumiem: :values.',
    'file' => 'Laukā :attribute jābūt failam.',
    'filled' => 'Laukam :attribute jābūt aizpildītam.',
    'gt' => [
        'array' => 'Laukā :attribute jābūt vairāk nekā :value vienībām.',
        'file' => 'Laukam :attribute jābūt lielākam par :value kilobaitiem.',
        'numeric' => 'Laukam :attribute jābūt lielākam par :value.',
        'string' => 'Laukā :attribute jābūt vairāk nekā :value rakstzīmēm.',
    ],
    'gte' => [
        'array' => 'Laukā :attribute jābūt vismaz :value vienībām.',
        'file' => 'Laukam :attribute jābūt vismaz :value kilobaitiem.',
        'numeric' => 'Laukam :attribute jābūt vismaz :value.',
        'string' => 'Laukā :attribute jābūt vismaz :value rakstzīmēm.',
    ],
    'hex_color' => 'Laukā :attribute jābūt derīgai heksadecimālai krāsai.',
    'image' => 'Laukā :attribute jābūt attēlam.',
    'in' => 'Izvēlētā vērtība laukā :attribute nav derīga.',
    'in_array' => 'Laukam :attribute jābūt starp :other.',
    'in_array_keys' => 'Laukā :attribute jābūt vismaz vienai no šīm atslēgām: :values.',
    'integer' => 'Laukā :attribute jābūt veselam skaitlim.',
    'ip' => 'Laukā :attribute jābūt derīgai IP adresei.',
    'ipv4' => 'Laukā :attribute jābūt derīgai IPv4 adresei.',
    'ipv6' => 'Laukā :attribute jābūt derīgai IPv6 adresei.',
    'json' => 'Laukā :attribute jābūt derīgai JSON virknei.',
    'list' => 'Laukam :attribute jābūt sarakstam.',
    'lowercase' => 'Laukā :attribute jābūt tikai mazajiem burtiem.',
    'lt' => [
        'array' => 'Laukā :attribute jābūt mazāk nekā :value vienībām.',
        'file' => 'Laukam :attribute jābūt mazākam par :value kilobaitiem.',
        'numeric' => 'Laukam :attribute jābūt mazākam par :value.',
        'string' => 'Laukā :attribute jābūt mazāk nekā :value rakstzīmēm.',
    ],
    'lte' => [
        'array' => 'Laukā :attribute nedrīkst būt vairāk par :value vienībām.',
        'file' => 'Laukam :attribute jābūt ne vairāk kā :value kilobaitiem.',
        'numeric' => 'Laukam :attribute jābūt ne vairāk kā :value.',
        'string' => 'Laukā :attribute jābūt ne vairāk kā :value rakstzīmēm.',
    ],
    'mac_address' => 'Laukā :attribute jābūt derīgai MAC adresei.',
    'max' => [
        'array' => 'Laukā :attribute nedrīkst būt vairāk par :max vienībām.',
        'file' => 'Lauks :attribute nedrīkst būt lielāks par :max kilobaitiem.',
        'numeric' => 'Lauks :attribute nedrīkst būt lielāks par :max.',
        'string' => 'Laukā :attribute nedrīkst būt vairāk par :max rakstzīmēm.',
    ],
    'max_digits' => 'Laukā :attribute nedrīkst būt vairāk par :max cipariem.',
    'mimes' => 'Laukā :attribute jābūt šāda tipa failam: :values.',
    'mimetypes' => 'Laukā :attribute jābūt šāda tipa failam: :values.',
    'min' => [
        'array' => 'Laukā :attribute jābūt vismaz :min vienībām.',
        'file' => 'Laukam :attribute jābūt vismaz :min kilobaitiem.',
        'numeric' => 'Laukam :attribute jābūt vismaz :min.',
        'string' => 'Laukā :attribute jābūt vismaz :min rakstzīmēm.',
    ],
    'min_digits' => 'Laukā :attribute jābūt vismaz :min cipariem.',
    'missing' => 'Laukam :attribute jātrūkst.',
    'missing_if' => 'Laukam :attribute jātrūkst, ja :other ir :value.',
    'missing_unless' => 'Laukam :attribute jātrūkst, ja vien :other nav :value.',
    'missing_with' => 'Laukam :attribute jātrūkst, ja ir norādīts :values.',
    'missing_with_all' => 'Laukam :attribute jātrūkst, ja ir norādīti :values.',
    'multiple_of' => 'Laukam :attribute jābūt :value daudzkārtnim.',
    'not_in' => 'Izvēlētā vērtība laukā :attribute nav derīga.',
    'not_regex' => 'Lauka :attribute formāts nav derīgs.',
    'numeric' => 'Laukā :attribute jābūt skaitlim.',
    'password' => [
        'letters' => 'Laukā :attribute jābūt vismaz vienam burtam.',
        'mixed' => 'Laukā :attribute jābūt vismaz vienam lielajam un vienam mazajam burtam.',
        'numbers' => 'Laukā :attribute jābūt vismaz vienam ciparam.',
        'symbols' => 'Laukā :attribute jābūt vismaz vienam simbolam.',
        'uncompromised' => 'Šī :attribute ir parādījusies datu noplūdē. Lūdzu, izvēlieties citu.',
    ],
    'present' => 'Laukam :attribute jābūt norādītam.',
    'present_if' => 'Laukam :attribute jābūt norādītam, ja :other ir :value.',
    'present_unless' => 'Laukam :attribute jābūt norādītam, ja vien :other nav :value.',
    'present_with' => 'Laukam :attribute jābūt norādītam, ja ir norādīts :values.',
    'present_with_all' => 'Laukam :attribute jābūt norādītam, ja ir norādīti :values.',
    'prohibited' => 'Lauks :attribute ir aizliegts.',
    'prohibited_if' => 'Lauks :attribute ir aizliegts, ja :other ir :value.',
    'prohibited_if_accepted' => 'Lauks :attribute ir aizliegts, ja :other ir apstiprināts.',
    'prohibited_if_declined' => 'Lauks :attribute ir aizliegts, ja :other ir noraidīts.',
    'prohibited_unless' => 'Lauks :attribute ir aizliegts, ja vien :other nav starp :values.',
    'prohibits' => 'Lauks :attribute neļauj norādīt :other.',
    'regex' => 'Lauka :attribute formāts nav derīgs.',
    'required' => 'Lauks :attribute ir obligāts.',
    'required_array_keys' => 'Laukā :attribute jābūt ierakstiem priekš: :values.',
    'required_if' => 'Lauks :attribute ir obligāts, ja :other ir :value.',
    'required_if_accepted' => 'Lauks :attribute ir obligāts, ja :other ir apstiprināts.',
    'required_if_declined' => 'Lauks :attribute ir obligāts, ja :other ir noraidīts.',
    'required_unless' => 'Lauks :attribute ir obligāts, ja vien :other nav starp :values.',
    'required_with' => 'Lauks :attribute ir obligāts, ja ir norādīts :values.',
    'required_with_all' => 'Lauks :attribute ir obligāts, ja ir norādīti :values.',
    'required_without' => 'Lauks :attribute ir obligāts, ja nav norādīts :values.',
    'required_without_all' => 'Lauks :attribute ir obligāts, ja nav norādīts neviens no :values.',
    'same' => 'Laukam :attribute jāsakrīt ar :other.',
    'size' => [
        'array' => 'Laukā :attribute jābūt :size vienībām.',
        'file' => 'Laukam :attribute jābūt :size kilobaitiem.',
        'numeric' => 'Laukam :attribute jābūt :size.',
        'string' => 'Laukā :attribute jābūt :size rakstzīmēm.',
    ],
    'starts_with' => 'Laukam :attribute jāsākas ar kādu no šiem: :values.',
    'string' => 'Laukā :attribute jābūt tekstam.',
    'timezone' => 'Laukā :attribute jābūt derīgai laika zonai.',
    'unique' => 'Šāds :attribute jau ir aizņemts.',
    'uploaded' => 'Lauku :attribute neizdevās augšupielādēt.',
    'uppercase' => 'Laukā :attribute jābūt tikai lielajiem burtiem.',
    'url' => 'Laukā :attribute jābūt derīgam URL.',
    'ulid' => 'Laukā :attribute jābūt derīgam ULID.',
    'uuid' => 'Laukā :attribute jābūt derīgam UUID.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    */

    'custom' => [
        'starts_at' => [
            'after' => 'Izvēlieties laiku nākotnē.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The field names this app's forms actually submit, so a message reads
    | "Lauks e-pasts ir obligāts" rather than exposing the raw input name.
    |
    */

    'attributes' => [
        'name' => 'vārds',
        'email' => 'e-pasts',
        'password' => 'parole',
        'password_confirmation' => 'paroles apstiprinājums',
        'current_password' => 'pašreizējā parole',
        'starts_at' => 'sākuma laiks',
        'duration_minutes' => 'ilgums',
        'group_size' => 'grupas lielums',
        'user_id' => 'lietotājs',
        'body' => 'ziņa',
        'q' => 'meklējums',
    ],

];
