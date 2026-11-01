<?php
declare(strict_types=1);

function gc_psgc_data(string $dataset): array
{
    static $cache = [];
    $files = [
        'regions' => 'regions.json',
        'provinces' => 'provinces.json',
        'cities' => 'cities_municipalities.json',
        'barangays' => 'barangays.json',
    ];
    if (!isset($files[$dataset])) {
        throw new InvalidArgumentException('Unknown PSGC dataset.');
    }
    if (isset($cache[$dataset])) {
        return $cache[$dataset];
    }

    $path = dirname(__DIR__) . '/data/psgc/' . $files[$dataset];
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('The local PSGC data file could not be read.');
    }
    $records = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($records)) {
        throw new RuntimeException('The local PSGC data file is invalid.');
    }
    $cache[$dataset] = $records;
    return $records;
}

function gc_philippine_address(
    mixed $country,
    mixed $provinceCode,
    mixed $cityCode,
    mixed $barangayCode,
    mixed $street
): ?string {
    if ($country !== 'PH' || !is_string($provinceCode) || !is_string($cityCode)
        || !is_string($barangayCode) || !is_string($street) || mb_strlen(trim($street)) > 100) {
        return null;
    }

    $province = null;
    if ($provinceCode === 'NCR') {
        $province = ['code' => 'NCR', 'name' => 'Metro Manila (NCR)'];
    } elseif (str_starts_with($provinceCode, 'REGION:')) {
        $regionCode = substr($provinceCode, 7);
        foreach (gc_psgc_data('regions') as $record) {
            if ($record['region_code'] === $regionCode) {
                $province = ['code' => $provinceCode, 'name' => $record['name']];
                break;
            }
        }
    }
    if ($province === null) {
        foreach (gc_psgc_data('provinces') as $record) {
            if ($record['code'] === $provinceCode) {
                $province = $record;
                break;
            }
        }
    }
    if ($province === null) {
        return null;
    }

    $city = null;
    foreach (gc_psgc_data('cities') as $record) {
        if ($record['code'] === $cityCode && $record['province_code'] === $provinceCode) {
            $city = $record;
            break;
        }
    }
    if ($city === null) {
        return null;
    }

    $barangay = null;
    $cityCodeForBarangay = substr($city['code'], 0, 7) . '000';
    foreach (gc_psgc_data('barangays') as $record) {
        if ($record['code'] === $barangayCode && $record['city_municipality_code'] === $cityCodeForBarangay) {
            $barangay = $record;
            break;
        }
    }
    if ($barangay === null) {
        return null;
    }

    $cityName = $city['name'];
    if ($city['level'] === 'SubMun') {
        foreach (gc_psgc_data('cities') as $parentCity) {
            if ($parentCity['code'] === $city['parent_city_code']) {
                $cityName .= ', ' . $parentCity['name'];
                break;
            }
        }
    }

    $segments = array_filter([
        trim($street),
        $barangay['name'],
        $cityName,
        $province['name'],
        'Philippines',
    ], static fn (string $segment): bool => $segment !== '');
    $address = implode(', ', $segments);
    return mb_strlen($address) <= 255 ? $address : null;
}
