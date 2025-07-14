<?php

return [
    'required' => 'Поле :attribute обязательно для заполнения.',
    'email' => 'Поле :attribute должно быть действительным email адресом.',
    'unique' => 'Такое значение поля :attribute уже используется.',
    'min' => [
        'string' => 'Поле :attribute должно содержать не менее :min символов.',
    ],
    'max' => [
        'string' => 'Поле :attribute не должно превышать :max символов.',
    ],

    'attributes' => [
        'email' => 'E-mail',
        'password' => 'пароль',
        'name' => 'имя',
    ],
]; 