<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/geography_data.php';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    gc_respond(405, ['error' => 'Unsupported request method.']);
}

try {
    $action = (string) ($_GET['action'] ?? '');
    if ($action === 'provinces') {
        $provinces = array_map(
            static fn (array $province): array => ['code' => $province['code'], 'name' => $province['name']],
            gc_psgc_data('provinces')
        );
        $provinces[] = ['code' => 'NCR', 'name' => 'Metro Manila (NCR)'];
        $regionsByCode = [];
        foreach (gc_psgc_data('regions') as $region) {
            $regionsByCode[$region['region_code']] = $region['name'];
        }
        $independentRegions = [];
        foreach (gc_psgc_data('cities') as $city) {
            if (str_starts_with($city['province_code'], 'REGION:')
                && isset($regionsByCode[$city['region_code']])) {
                $independentRegions[$city['province_code']] =
                    $regionsByCode[$city['region_code']] . ' — independent cities/municipalities';
            }
        }
        foreach ($independentRegions as $code => $name) {
            $provinces[] = ['code' => $code, 'name' => $name];
        }
        usort($provinces, static fn (array $first, array $second): int => strnatcasecmp($first['name'], $second['name']));
        gc_respond(200, ['provinces' => $provinces]);
    }

    if (!in_array($action, ['cities', 'barangays'], true)) {
        gc_respond(400, ['error' => 'Unsupported geography action.']);
    }

    $provinceCode = (string) ($_GET['province_code'] ?? '');
    if ($provinceCode === 'NCR') {
        $validProvince = true;
    } elseif (str_starts_with($provinceCode, 'REGION:')) {
        $validProvince = false;
        foreach (gc_psgc_data('cities') as $record) {
            if ($record['province_code'] === $provinceCode) {
                $validProvince = true;
                break;
            }
        }
    } else {
        $validProvince = false;
        foreach (gc_psgc_data('provinces') as $record) {
            if ($record['code'] === $provinceCode) {
                $validProvince = true;
                break;
            }
        }
    }
    if (!$validProvince) {
        gc_respond(422, ['error' => 'Choose a valid Philippine province.']);
    }

    if ($action === 'cities') {
        $cities = [];
        foreach (gc_psgc_data('cities') as $record) {
            if ($record['province_code'] === $provinceCode) {
                $name = $record['name'];
                if ($record['level'] === 'SubMun') {
                    foreach (gc_psgc_data('cities') as $parentCity) {
                        if ($parentCity['code'] === $record['parent_city_code']) {
                            $name = $parentCity['name'] . ' - ' . $name;
                            break;
                        }
                    }
                }
                $cities[] = ['code' => $record['code'], 'name' => $name];
            }
        }
        usort($cities, static fn (array $first, array $second): int => strnatcasecmp($first['name'], $second['name']));
        gc_respond(200, ['cities' => $cities]);
    }

    if ($action === 'barangays') {
        $cityCode = (string) ($_GET['city_code'] ?? '');
        $city = null;
        foreach (gc_psgc_data('cities') as $record) {
            if ($record['code'] === $cityCode && $record['province_code'] === $provinceCode) {
                $city = $record;
                break;
            }
        }
        if ($city === null) {
            gc_respond(422, ['error' => 'Choose a valid city or municipality.']);
        }

        $barangays = [];
        $cityCodeForBarangay = substr($city['code'], 0, 7) . '000';
        foreach (gc_psgc_data('barangays') as $record) {
            if ($record['city_municipality_code'] === $cityCodeForBarangay) {
                $barangays[] = ['code' => $record['code'], 'name' => $record['name']];
            }
        }
        usort($barangays, static fn (array $first, array $second): int => strnatcasecmp($first['name'], $second['name']));
        gc_respond(200, ['barangays' => $barangays]);
    }
} catch (Throwable $exception) {
    error_log('GentleCare PSGC lookup failed: ' . $exception->getMessage());
    gc_respond(503, ['error' => 'The Philippine location list is temporarily unavailable.']);
}
