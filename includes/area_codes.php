<?php
declare(strict_types=1);

/**
 * US time zones for lead phones, worked out from the area code (first 3 digits of lead_phones.number_key).
 * An area code that straddles a zone line is filed under the zone most of its callers are in
 * (e.g. 850/448 Florida panhandle → Central, 812 southern Indiana → Eastern, 208 Idaho → Mountain).
 * Arizona has its own entry because it does not observe daylight saving time.
 */

/** key => [label, short label, PHP timezone] in display order. */
const US_TIME_ZONES = [
    'eastern' => ['Eastern', 'ET', 'America/New_York'],
    'central' => ['Central', 'CT', 'America/Chicago'],
    'mountain' => ['Mountain', 'MT', 'America/Denver'],
    'arizona' => ['Arizona', 'MST', 'America/Phoenix'],
    'pacific' => ['Pacific', 'PT', 'America/Los_Angeles'],
    'alaska' => ['Alaska', 'AKT', 'America/Anchorage'],
    'hawaii' => ['Hawaii', 'HT', 'Pacific/Honolulu'],
    'atlantic' => ['Atlantic (PR, USVI)', 'AT', 'America/Puerto_Rico'],
    'chamorro' => ['Chamorro (Guam)', 'ChT', 'Pacific/Guam'],
    'samoa' => ['Samoa', 'SST', 'Pacific/Pago_Pago'],
];

const US_AREA_CODES = [
    'eastern' => [
        203, 475, 860, 959, // CT
        302, // DE
        202, 771, // DC
        239, 305, 321, 324, 352, 386, 407, 561, 645, 656, 689, 727, 728, 754, 772, 786, 813, 863, 904, 941, 954, // FL
        229, 404, 470, 478, 678, 706, 762, 770, 912, 943, // GA
        260, 317, 463, 574, 765, 812, 930, // IN
        502, 606, 859, // KY
        207, // ME
        227, 240, 301, 410, 443, 667, // MD
        339, 351, 413, 508, 617, 774, 781, 857, 978, // MA
        231, 248, 269, 313, 517, 586, 616, 679, 734, 810, 906, 947, 989, // MI
        603, // NH
        201, 551, 609, 640, 732, 848, 856, 862, 908, 973, // NJ
        212, 315, 329, 332, 347, 363, 516, 518, 585, 607, 624, 631, 646, 680, 716, 718, 838, 845, 914, 917, 929, 934, // NY
        252, 336, 472, 704, 743, 828, 910, 919, 980, 984, // NC
        216, 220, 234, 283, 326, 330, 380, 419, 436, 440, 513, 567, 614, 740, 937, // OH
        215, 223, 267, 272, 412, 445, 484, 570, 582, 610, 717, 724, 814, 835, 878, // PA
        401, // RI
        803, 821, 839, 843, 854, 864, // SC
        423, 729, 865, // TN (east)
        802, // VT
        276, 434, 540, 571, 686, 703, 757, 804, 826, 948, // VA
        304, 681, // WV
    ],
    'central' => [
        205, 251, 256, 334, 659, 938, // AL
        327, 479, 501, 870, // AR
        448, 850, // FL (panhandle)
        217, 224, 309, 312, 331, 447, 464, 618, 630, 708, 730, 773, 779, 815, 847, 861, 872, // IL
        219, // IN (northwest)
        319, 515, 563, 641, 712, // IA
        316, 620, 785, 913, // KS
        270, 364, // KY (west)
        225, 318, 337, 457, 504, 985, // LA
        218, 320, 507, 612, 651, 763, 924, 952, // MN
        228, 601, 662, 769, // MS
        235, 314, 417, 557, 573, 636, 660, 816, 975, // MO
        308, 402, 531, // NE
        701, // ND
        605, // SD
        405, 539, 572, 580, 918, // OK
        615, 629, 731, 901, // TN (middle, west)
        210, 214, 254, 281, 325, 346, 361, 409, 430, 432, 469, 512, 682, 713, 726, 737, 806, 817, 830, 832, 903, 936, 940, 945, 956, 972, 979, // TX
        262, 274, 353, 414, 534, 608, 715, 920, // WI
    ],
    'mountain' => [
        303, 719, 720, 970, 983, // CO
        208, 986, // ID
        406, // MT
        505, 575, // NM
        915, // TX (El Paso)
        385, 435, 801, // UT
        307, // WY
    ],
    'arizona' => [480, 520, 602, 623, 928],
    'pacific' => [
        209, 213, 279, 310, 323, 341, 350, 369, 408, 415, 424, 442, 510, 530, 559, 562, 619, 626, 628, 650, 657, 661, 669,
        707, 714, 738, 747, 760, 805, 818, 820, 831, 840, 858, 909, 916, 925, 949, 951, // CA
        702, 725, 775, // NV
        458, 503, 541, 971, // OR
        206, 253, 360, 425, 509, 564, // WA
    ],
    'alaska' => [907],
    'hawaii' => [808],
    'atlantic' => [340, 787, 939],
    'chamorro' => [670, 671],
    'samoa' => [684],
];

/** Time-zone key for a phone's number_key (last 10 digits), or null when it is not a known US area code. */
function area_code_zone(?string $numberKey): ?string
{
    static $byCode = null;
    if ($byCode === null) {
        $byCode = [];
        foreach (US_AREA_CODES as $zone => $codes) {
            foreach ($codes as $code) {
                $byCode[(string) $code] = $zone;
            }
        }
    }
    return $numberKey !== null && strlen($numberKey) === 10 ? ($byCode[substr($numberKey, 0, 3)] ?? null) : null;
}

/** Current local time in a zone, e.g. "2:14 PM". */
function us_zone_local_time(string $zone): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(US_TIME_ZONES[$zone][2])))->format('g:i A');
}

/** SQL CASE mapping a number_key column to its time-zone key (NULL when unknown). Codes are integer constants. */
function area_code_zone_case_sql(string $col): string
{
    $sql = 'CASE';
    foreach (US_AREA_CODES as $zone => $codes) {
        $sql .= " WHEN LEFT($col, 3) IN ('" . implode("','", $codes) . "') THEN '$zone'";
    }
    return "IF(CHAR_LENGTH($col) = 10, $sql END, NULL)";
}

/** SQL condition: number_key column is in one of the given zones. */
function area_code_in_zones_sql(string $col, array $zones): string
{
    $codes = array_merge(...array_map(fn($z) => US_AREA_CODES[$z], $zones));
    return "(CHAR_LENGTH($col) = 10 AND LEFT($col, 3) IN ('" . implode("','", $codes) . "'))";
}
