<?php
return [
    'districts' => ['Rajkot', 'Junagadh', 'Jamnagar', 'Amreli'],
    'departments' => ['Dispatch', 'Store', 'QC', 'Production'],
    'types' => [
        'Short Supply',
        'Material Fault',
        'Wrong Dispatch',
        'Exceed Supply',
        'Other'
    ],
    'routing' => [
        'Short Supply' => ['Dispatch', 'Store'],
        'Material Fault' => ['QC'],
        'Wrong Dispatch' => ['Dispatch', 'Store'],
        'Exceed Supply' => ['Dispatch', 'Store'],
        'Other' => ['Admin']
    ],
    'quantity_units' => ['Nos', 'Meter', 'Piece', 'Roll'],
    'products' => [
        'HDPE Sprinkler Pipe 75mm 2.5 Kg/cm² QAC (C-Clamp) 3m',
        'Emitting Pipe 16x2x50 (Class-II) Round',
        'Emitting Pipe 20x2x40 (Class-I) Flat'
    ]
];
