<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/geography_data.php';

function gc_geography_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$regions = gc_psgc_data('regions');
$provinces = gc_psgc_data('provinces');
$cities = gc_psgc_data('cities');
$barangays = gc_psgc_data('barangays');
gc_geography_expect(count($regions) === 18, 'The PSGC dataset must contain 18 regions.');
gc_geography_expect(count($provinces) === 82, 'The PSGC dataset must contain 82 provinces.');
gc_geography_expect(count($cities) === 1656, 'The PSGC dataset must contain 1,656 cities, municipalities, and sub-municipalities.');
gc_geography_expect(count($barangays) === 42010, 'The PSGC dataset must contain 42,010 barangays.');

$provinceCodes = array_fill_keys(array_column($provinces, 'code'), true);
$regionCodes = array_fill_keys(array_column($regions, 'region_code'), true);
$citiesByCode = [];
foreach ($cities as $city) {
    $citiesByCode[$city['code']] = $city;
}
foreach ($cities as $city) {
    if ($city['province_code'] === 'NCR') {
        gc_geography_expect($city['region_code'] === '13', 'Only NCR entities may use the NCR selector.');
    } elseif (str_starts_with($city['province_code'], 'REGION:')) {
        gc_geography_expect(
            isset($regionCodes[$city['region_code']])
                && $city['province_code'] === 'REGION:' . $city['region_code'],
            'Independent city or municipality references an invalid region.'
        );
    } else {
        gc_geography_expect(
            isset($provinceCodes[$city['province_code']]),
            'City or municipality references a province absent from the PSGC data.'
        );
    }
    if ($city['level'] === 'SubMun') {
        gc_geography_expect(
            isset($citiesByCode[$city['parent_city_code']]),
            'Sub-municipality references a parent city absent from the PSGC data.'
        );
    }
}
$cityCodes = array_fill_keys(array_column($cities, 'code'), true);
foreach ($barangays as $barangay) {
    gc_geography_expect(
        isset($cityCodes[$barangay['city_municipality_code']]),
        'Barangay references a city or municipality absent from the PSGC data.'
    );
}

$cebuAddress = gc_philippine_address('PH', '0702200000', '0702201000', '0702201001', 'Osmeña Street');
gc_geography_expect(
    $cebuAddress === 'Osmeña Street, Cabadiangan, Alcantara, Cebu, Philippines',
    'The Cebu province/city/barangay hierarchy did not resolve to its canonical address.'
);

$ncrAddress = gc_philippine_address('PH', 'NCR', '1380601000', '1380601001', '');
gc_geography_expect(
    $ncrAddress === 'Barangay 1, Tondo I/II, City of Manila, Metro Manila (NCR), Philippines',
    'The NCR sub-municipality address did not include its parent city.'
);

$angelesAddress = gc_philippine_address('PH', 'REGION:03', '0330100000', '0330100001', '');
gc_geography_expect(
    $angelesAddress === 'Agapito del Rosario, City of Angeles, Region III (Central Luzon), Philippines',
    'The independent-city address was not kept within its PSA region.'
);

$barmmAddress = gc_philippine_address('PH', 'REGION:19', '1999901000', '1999901001', '');
gc_geography_expect(
    $barmmAddress === 'Kib-Ayao, Kapalawan, Bangsamoro Autonomous Region In Muslim Mindanao (BARMM), Philippines',
    'The BARMM special-area municipality was not kept within its PSA region.'
);

gc_geography_expect(
    gc_philippine_address('PH', 'NCR', '0330100000', '0330100001', '') === null,
    'An independent city outside the selected region must be rejected.'
);
gc_geography_expect(
    gc_philippine_address('PH', '0702200000', '0702201000', '1380601001', '') === null,
    'A barangay outside the selected city must be rejected.'
);
gc_geography_expect(
    gc_philippine_address('US', '0702200000', '0702201000', '0702201001', '') === null,
    'A non-Philippines country must be rejected.'
);

echo "PASS PSGC dataset counts, province/NCR/independent-region hierarchy, and invalid parent rejection\n";
