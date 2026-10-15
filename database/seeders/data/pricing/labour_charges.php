<?php

/*
 * Schedule of Labour Charges, transcribed from Design/EA Prices/Labor Charges.jpg.
 * Rates are GST-inclusive (18%). null = not offered for that car ("-" on the chart).
 *
 * Columns: [service, unit, Hatchback petrol, Hatchback diesel, Prem. Hatch/Sedan/Compact SUV petrol,
 *           Prem. Hatch/Sedan/Compact SUV diesel, SUV, Premium SUV, Premium Luxury]
 * The chart gives one rate for SUV, Premium SUV and Premium Luxury; it applies to every fuel.
 */

return [
    'columns' => [
        // chart column => [category slugs, fuel groups]
        2 => [['hatchback'], ['petrol']],
        3 => [['hatchback'], ['diesel']],
        4 => [['sedan-compact-suv'], ['petrol']],
        5 => [['sedan-compact-suv'], ['diesel']],
        6 => [['suv-muv'], ['petrol', 'diesel']],
        7 => [['premium-suv'], ['petrol', 'diesel']],
        8 => [['luxury-class-1', 'luxury-class-2', 'luxury-class-3'], ['petrol', 'diesel']],
    ],

    'services' => [
        ['Engine Oil & Oil Filter Replacement', 'job', 472, 472, 590, 590, 590, 708, 944],
        ['Engine Tuning (Spark Plug Cleaning, Adjustment & Air Filter Cleaning)', 'job', 472, 118, 472, 118, 118, 236, 236],
        ['Brake Service', 'job', 1180, 1180, 1475, 1475, 1770, 1770, 2360],
        ['Injector Cleaning (Petrol Cars only - Per Injector)', 'job', 590, 1770, 531, 708, 2065, 2596, 4720],
        ['Battery Jump Start', 'job', 295, 295, 354, 354, 354, 354, 472],
        ['Battery Remove/Refit & Charging', 'job', 708, 708, 767, 767, 826, 826, 1062],
        ['Fuel Filter Replacement', 'job', 354, 472, 354, 472, 590, 649, 1180],
        ['Clutch Overhaul', 'job', 3540, 3835, 3835, 4425, 5605, 5605, null],
        ['Front Shock Absorber Assembly Set Replacement', 'job', 1770, 1770, 2065, 2065, 3540, 4130, 4720],
        ['Front Complete Suspension Replacement', 'job', 3540, 3540, 4720, 4720, 5900, 7080, 9440],
        ['Steering Rack Replacement', 'job', 1770, 1770, 2360, 2360, 3540, 4130, 5900],
        ['Rear Shock Absorber Assembly Set Replacement', 'job', 1475, 1475, 1770, 1770, 2950, 4130, 5900],
        ['Rear Complete Suspension Replacement', 'job', 2065, 2065, 2950, 2950, 4720, 5900, 7080],
        ['Engine Mounts Replacement', 'job', 1475, 1475, 2065, 2065, 2950, 3540, 5900],
        ['Radiator/Fan Replacement', 'job', 1180, 1180, 1534, 1534, 1888, 1888, 2950],
        ['Door Trim Remove/Refit, Glass Winder & Hinges Lubrication (Per Door)', 'job', 236, 236, 354, 354, 531, 531, 944],
        ['Door Glass and/or Winder Fixing (Per Door)', 'job', 590, 590, 708, 708, 944, 1062, 1475],
        ['Timing Belt Replacement', 'job', 2360, 2950, 2950, 3540, 4720, 5900, 10620],
        ['Timing Chain Replacement', 'job', 2950, 3540, 3540, 4720, 4720, 5900, 11800],
        ['Starter/Alternator Removal & Fitting (Repairs at Actuals)', 'job', 944, 944, 1180, 1180, 1475, 1770, 2360],
        ['Throttle Body Servicing', 'job', 1475, null, 1770, null, null, null, null],
        ['EGR Servicing', 'job', null, 2950, null, 3540, 3540, 4720, 8260],
        ['Intercooler Servicing', 'job', null, 1770, null, 2360, 2360, 3540, 5900],
        ['On Board Diagnostics Scanning', 'job', 590, 590, 708, 708, 708, 944, 1180],
        ['Brake Pads Set Replacement', 'job', 708, 708, 944, 944, 1062, 1180, 1770],
        ['Brake Cylinders Set Replacement', 'job', 1180, 1180, 1298, 1298, 1475, 1652, null],
        ['Brake Booster Replacement', 'job', 1475, 1475, 1770, 1770, 2360, 3540, 5900],
        ['Brake Rotor Disc Skimming', 'job', 1180, 1180, 1425, 1425, 1750, 1900, 2350],
        ['Brake Drum Skimming', 'job', 1180, 1180, 1425, 1425, 1750, 1900, null],
        ['AC Gas Complete Charging', 'job', 2124, 2124, 2124, 2124, 2596, 2950, 4130],
        ['AC Basic Service (Gas Top-up)', 'job', 1770, 1770, 1770, 1770, 2550, 3550, 4750],
        ['AC Complete Service', 'job', 4130, 4130, 4130, 4130, 5310, 7080, 11800],
        ['Other General Repairs (Per Hour Per Technician)', 'hour', 295, 295, 295, 295, 413, 531, 708],
    ],
];
