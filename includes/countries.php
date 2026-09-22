<?php
// includes/countries.php - Standardized Country Master List, ISO Code & Accurate Geographic Coordinate Resolver
// (self-contained: no dependency on functions.php - avoids a circular require)

/**
 * Safe string lowercase helper for wide PHP support
 */
function safe_strtolower($str) {
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($str, 'UTF-8');
    }
    return strtolower($str);
}

/**
 * Standard World Countries Array (ISO 3166-1 alpha-2 => Standard Name)
 */
function get_standard_countries_list() {
    return [
        "AF" => "Afghanistan", "AL" => "Albania", "DZ" => "Algeria", "AD" => "Andorra",
        "AO" => "Angola", "AR" => "Argentina", "AM" => "Armenia", "AU" => "Australia",
        "AT" => "Austria", "AZ" => "Azerbaijan", "BS" => "Bahamas", "BH" => "Bahrain",
        "BD" => "Bangladesh", "BY" => "Belarus", "BE" => "Belgium", "BZ" => "Belize",
        "BT" => "Bhutan", "BO" => "Bolivia", "BA" => "Bosnia and Herzegovina", "BW" => "Botswana",
        "BR" => "Brazil", "BN" => "Brunei", "BG" => "Bulgaria", "KH" => "Cambodia",
        "CM" => "Cameroon", "CA" => "Canada", "CL" => "Chile", "CN" => "China",
        "CO" => "Colombia", "CR" => "Costa Rica", "HR" => "Croatia", "CU" => "Cuba",
        "CY" => "Cyprus", "CZ" => "Czech Republic", "DK" => "Denmark", "DO" => "Dominican Republic",
        "EC" => "Ecuador", "EG" => "Egypt", "SV" => "El Salvador", "EE" => "Estonia",
        "ET" => "Ethiopia", "FI" => "Finland", "FR" => "France", "GE" => "Georgia",
        "DE" => "Germany", "GH" => "Ghana", "GR" => "Greece", "GT" => "Guatemala",
        "HN" => "Honduras", "HK" => "Hong Kong", "HU" => "Hungary", "IS" => "Iceland",
        "IN" => "India", "ID" => "Indonesia", "IR" => "Iran", "IQ" => "Iraq",
        "IE" => "Ireland", "IL" => "Israel", "IT" => "Italy", "JM" => "Jamaica",
        "JP" => "Japan", "JO" => "Jordan", "KZ" => "Kazakhstan", "KE" => "Kenya",
        "KW" => "Kuwait", "KG" => "Kyrgyzstan", "LA" => "Laos", "LV" => "Latvia",
        "LB" => "Lebanon", "LY" => "Libya", "LT" => "Lithuania", "LU" => "Luxembourg",
        "MY" => "Malaysia", "MV" => "Maldives", "MT" => "Malta", "MX" => "Mexico",
        "MD" => "Moldova", "MC" => "Monaco", "MN" => "Mongolia", "ME" => "Montenegro",
        "MA" => "Morocco", "MM" => "Myanmar", "NP" => "Nepal", "NL" => "Netherlands",
        "NZ" => "New Zealand", "NI" => "Nicaragua", "NG" => "Nigeria", "NO" => "Norway",
        "OM" => "Oman", "PK" => "Pakistan", "PS" => "Palestine", "PA" => "Panama",
        "PY" => "Paraguay", "PE" => "Peru", "PH" => "Philippines", "PL" => "Poland",
        "PT" => "Portugal", "QA" => "Qatar", "RO" => "Romania", "RU" => "Russia",
        "SA" => "Saudi Arabia", "RS" => "Serbia", "SG" => "Singapore", "SK" => "Slovakia",
        "SI" => "Slovenia", "ZA" => "South Africa", "KR" => "South Korea", "ES" => "Spain",
        "LK" => "Sri Lanka", "SE" => "Sweden", "CH" => "Switzerland", "SY" => "Syria",
        "TW" => "Taiwan", "TH" => "Thailand", "TN" => "Tunisia", "TR" => "Turkey",
        "UA" => "Ukraine", "AE" => "United Arab Emirates", "GB" => "United Kingdom",
        "US" => "United States", "UY" => "Uruguay", "UZ" => "Uzbekistan", "VE" => "Venezuela",
        "VN" => "Vietnam", "YE" => "Yemen", "ZW" => "Zimbabwe",
        "ROM" => "Roman Empire", "BYZ" => "Byzantine Empire", "OTT" => "Ottoman Empire", "SUN" => "Soviet Union",
    ];
}

/**
 * Intelligent ISO-3166 & Region Code Resolver
 */
function get_country_iso_code($countryName) {
    if (empty($countryName)) return null;

    $raw = trim($countryName);
    $clean = trim(preg_replace('/[^a-z0-9 ]/i', '', safe_strtolower($raw)));

    $aliases = [
        'greece' => 'GR', 'ancient greece' => 'GR', 'greek empire' => 'GR',
        'united states' => 'US', 'united states of america' => 'US', 'usa' => 'US', 'us' => 'US',
        'united kingdom' => 'GB', 'uk' => 'GB', 'great britain' => 'GB', 'britain' => 'GB', 'england' => 'GB',
        'roman empire' => 'IT', 'ancient rome' => 'IT', 'rome' => 'IT',
        'bangladesh' => 'BD', 'bd' => 'BD', 'bengal' => 'BD',
        'soviet union' => 'RU', 'ussr' => 'RU', 'russia' => 'RU', 'russian empire' => 'RU', 'cccp' => 'RU',
        'france' => 'FR', 'french republic' => 'FR',
        'germany' => 'DE', 'german empire' => 'DE',
        'japan' => 'JP', 'india' => 'IN', 'british india' => 'IN',
        'china' => 'CN', 'egypt' => 'EG', 'ancient egypt' => 'EG',
        'turkey' => 'TR', 'ottoman empire' => 'TR', 'canada' => 'CA',
        'australia' => 'AU', 'spain' => 'ES', 'italy' => 'IT',
        'croatia' => 'HR', 'iraq' => 'IQ', 'iran' => 'IR',
        'netherlands' => 'NL', 'holland' => 'NL', 'switzerland' => 'CH',
        'austria' => 'AT', 'sweden' => 'SE', 'norway' => 'NO',
        'denmark' => 'DK', 'belgium' => 'BE', 'portugal' => 'PT',
        'saudi arabia' => 'SA', 'united arab emirates' => 'AE', 'uae' => 'AE',
    ];

    if (isset($aliases[$clean])) {
        return $aliases[$clean];
    }

    $list = get_standard_countries_list();
    foreach ($list as $code => $name) {
        $cName = trim(preg_replace('/[^a-z0-9 ]/i', '', safe_strtolower($name)));
        if ($cName === $clean) {
            return strlen($code) === 2 ? $code : null;
        }
    }

    return null;
}

/**
 * Master Country Geocoding Matrix (Smart Multi-Tier Matcher with Substring & Parentheses Support)
 */
function get_country_coordinates($countryName) {
    if (empty($countryName)) return null;

    $raw = trim($countryName);
    $clean = trim(preg_replace('/[^a-z0-9 ]/i', ' ', safe_strtolower($raw)));
    $clean = preg_replace('/\s+/', ' ', $clean);
    $cleanNoPunct = trim(preg_replace('/[^a-z0-9]/i', '', safe_strtolower($raw)));

    $coords = [
        // Soviet Union / USSR / Russia (Moscow / Kremlin / Central Heart of USSR)
        'soviet union' => [55.7558, 37.6173],
        'ussr' => [55.7558, 37.6173],
        'union of soviet socialist republics' => [55.7558, 37.6173],
        'soviet' => [55.7558, 37.6173],
        'cccp' => [55.7558, 37.6173],
        'russia' => [55.7558, 37.6173],
        'russian federation' => [55.7558, 37.6173],
        'russian empire' => [55.7558, 37.6173],

        // Asia & Middle East
        'bangladesh' => [23.6850, 90.3563], 'bd' => [23.6850, 90.3563], 'bengal' => [23.6850, 90.3563],
        'iraq' => [33.3152, 44.3661], 'mesopotamia' => [33.3152, 44.3661],
        'iran' => [32.4279, 53.6880], 'persia' => [32.4279, 53.6880],
        'saudi arabia' => [23.8859, 45.0792],
        'united arab emirates' => [23.4241, 53.8478], 'uae' => [23.4241, 53.8478],
        'qatar' => [25.3548, 51.1839], 'kuwait' => [29.3117, 47.4818], 'bahrain' => [26.0667, 50.5577],
        'oman' => [21.4735, 55.9754], 'yemen' => [15.5527, 48.5164],
        'syria' => [34.8021, 38.9968], 'jordan' => [30.5852, 36.2384], 'lebanon' => [33.8547, 35.8623],
        'israel' => [31.0461, 34.8516], 'palestine' => [31.9522, 35.2332],
        'turkey' => [38.9637, 35.2433], 'ottoman empire' => [41.0082, 28.9784],
        'india' => [20.5937, 78.9629], 'british india' => [20.5937, 78.9629],
        'pakistan' => [30.3753, 69.3451], 'sri lanka' => [7.8731, 80.7718], 'ceylon' => [7.8731, 80.7718],
        'nepal' => [28.3949, 84.1240], 'bhutan' => [27.5142, 90.4336], 'maldives' => [3.2028, 73.2207],
        'china' => [35.8617, 104.1954], 'japan' => [36.2048, 138.2529],
        'south korea' => [35.9078, 127.7669], 'korea' => [35.9078, 127.7669], 'north korea' => [40.3399, 127.5101],
        'taiwan' => [23.6978, 120.9605], 'hong kong' => [22.3193, 114.1694], 'macau' => [22.1987, 113.5439],
        'mongolia' => [46.8625, 103.8467],
        'thailand' => [15.8700, 100.9925], 'siam' => [15.8700, 100.9925],
        'indonesia' => [-0.7893, 113.9213], 'malaysia' => [4.2105, 101.9758], 'singapore' => [1.3521, 103.8198],
        'vietnam' => [14.0583, 108.2772], 'philippines' => [12.8797, 121.7740],
        'myanmar' => [21.9162, 95.9560], 'burma' => [21.9162, 95.9560],
        'cambodia' => [12.5657, 104.9910], 'laos' => [19.8563, 102.4955], 'brunei' => [4.5353, 114.7277],
        'afghanistan' => [33.9391, 67.7100], 'kazakhstan' => [48.0196, 66.9237],
        'uzbekistan' => [41.3775, 64.5853], 'turkmenistan' => [38.9697, 59.5563],
        'kyrgyzstan' => [41.2044, 74.7661], 'tajikistan' => [38.8610, 71.2761],
        'georgia' => [42.3154, 43.3569], 'armenia' => [40.0691, 45.0382], 'azerbaijan' => [40.1431, 47.5769],

        // Europe
        'croatia' => [45.1000, 15.2000], 'hrvatska' => [45.1000, 15.2000],
        'greece' => [39.0742, 21.8243], 'ancient greece' => [37.9838, 23.7275], 'hellas' => [39.0742, 21.8243],
        'italy' => [41.8719, 12.5674], 'roman empire' => [41.9028, 12.4964], 'ancient rome' => [41.9028, 12.4964], 'byzantine empire' => [41.0082, 28.9784],
        'united kingdom' => [55.3781, -3.4360], 'uk' => [55.3781, -3.4360], 'great britain' => [55.3781, -3.4360], 'england' => [52.3555, -1.1743], 'scotland' => [56.4907, -4.2026],
        'ireland' => [53.1424, -7.6921], 'france' => [46.2276, 2.2137], 'germany' => [51.1657, 10.4515],
        'spain' => [40.4637, -3.7492], 'portugal' => [39.3999, -8.2245],
        'netherlands' => [52.1326, 5.2913], 'holland' => [52.1326, 5.2913], 'belgium' => [50.5039, 4.4699],
        'switzerland' => [46.8182, 8.2275], 'austria' => [47.5162, 14.5501], 'austriahungary' => [47.5162, 14.5501],
        'sweden' => [60.1282, 18.6435], 'norway' => [60.4720, 8.4689], 'denmark' => [56.2639, 9.5018],
        'finland' => [61.9241, 25.7482], 'iceland' => [64.9631, -19.0208],
        'poland' => [51.9194, 19.1451], 'czech republic' => [49.8175, 15.4730], 'slovakia' => [48.6690, 19.6990],
        'hungary' => [47.1625, 19.5033], 'romania' => [45.9432, 24.9668], 'bulgaria' => [42.7339, 25.4858],
        'serbia' => [44.0165, 21.0059], 'bosnia and herzegovina' => [43.9159, 17.6791], 'slovenia' => [46.1512, 14.9955],
        'montenegro' => [42.7087, 19.3744], 'north macedonia' => [41.6086, 21.7453], 'albania' => [41.1533, 20.1683],
        'cyprus' => [35.1264, 33.4299], 'malta' => [35.9375, 14.3754], 'luxembourg' => [49.8153, 6.1296],
        'monaco' => [43.7384, 7.4246], 'vatican city' => [41.9029, 12.4534], 'san marino' => [43.9424, 12.4578],
        'ukraine' => [48.3794, 31.1656], 'belarus' => [53.7098, 27.9534], 'moldova' => [47.4116, 28.3699],
        'lithuania' => [55.1694, 23.8813], 'latvia' => [56.8796, 24.6032], 'estonia' => [58.5953, 25.0136],

        // Americas
        'united states' => [37.0902, -95.7129], 'united states of america' => [37.0902, -95.7129], 'usa' => [37.0902, -95.7129], 'us' => [37.0902, -95.7129],
        'canada' => [56.1304, -106.3468], 'mexico' => [23.6345, -102.5528],
        'brazil' => [-14.2350, -51.9253], 'argentina' => [-38.4161, -63.6167],
        'chile' => [-35.6751, -71.5430], 'peru' => [-9.1900, -75.0152], 'colombia' => [4.5709, -74.2973],
        'venezuela' => [6.4238, -66.5897], 'ecuador' => [-1.8312, -78.1834], 'bolivia' => [-16.2902, -63.5887],
        'paraguay' => [-23.4425, -58.4438], 'uruguay' => [-32.5228, -55.7658],
        'cuba' => [21.5218, -77.7812], 'dominican republic' => [18.7357, -70.1627], 'haiti' => [18.9712, -72.2852],
        'jamaica' => [18.1096, -77.2975], 'bahamas' => [25.0343, -77.3963], 'trinidad and tobago' => [10.6918, -61.2225],
        'panama' => [8.5379, -80.7821], 'costa rica' => [9.7489, -83.7534], 'nicaragua' => [12.8654, -85.2072],
        'honduras' => [15.2000, -86.2419], 'el salvador' => [13.7942, -88.8965], 'guatemala' => [15.7835, -90.2308],

        // Africa
        'egypt' => [26.8206, 30.8025], 'ancient egypt' => [26.8206, 30.8025],
        'south africa' => [-30.5595, 22.9375], 'nigeria' => [9.0820, 8.6753], 'ghana' => [7.9465, -1.0232],
        'kenya' => [-0.0236, 37.9062], 'ethiopia' => [9.1450, 40.4897], 'morocco' => [31.7917, -7.0926],
        'algeria' => [28.0339, 1.6596], 'tunisia' => [33.8869, 9.5375], 'libya' => [26.3351, 17.2283],
        'sudan' => [12.8628, 30.2176], 'tanzania' => [-6.3690, 34.8888], 'uganda' => [1.3733, 32.2903],
        'madagascar' => [-18.7669, 46.8691], 'mauritius' => [-20.3484, 57.5522], 'angola' => [-11.2027, 17.8739],
        'zimbabwe' => [-19.0154, 29.1549], 'zambia' => [-13.1339, 27.8493], 'mozambique' => [-18.6657, 35.5296],
        'senegal' => [14.4974, -14.4524], 'ivory coast' => [7.5400, -5.5471], 'cameroon' => [7.3697, 12.3547],

        // Oceania
        'australia' => [-25.2744, 133.7751], 'new zealand' => [-40.9006, 174.8860],
        'fiji' => [-17.7134, 178.0650], 'papua new guinea' => [-6.3150, 143.9555],
    ];

    // 1. Direct match with spaces
    if (isset($coords[$clean])) {
        return $coords[$clean];
    }
    // Direct match without punctuation
    if (isset($coords[$cleanNoPunct])) {
        return $coords[$cleanNoPunct];
    }

    // 2. Extract segments separated by parentheses or slashes (e.g. "Soviet Union (USSR)" -> ["Soviet Union", "USSR"])
    if (preg_match('/^([^(]+)\s*\(([^)]+)\)/i', $raw, $matches)) {
        $p1 = trim(preg_replace('/[^a-z0-9 ]/i', ' ', safe_strtolower($matches[1])));
        $p2 = trim(preg_replace('/[^a-z0-9 ]/i', ' ', safe_strtolower($matches[2])));
        $p1No = trim(preg_replace('/[^a-z0-9]/i', '', safe_strtolower($matches[1])));
        $p2No = trim(preg_replace('/[^a-z0-9]/i', '', safe_strtolower($matches[2])));

        if (isset($coords[$p1])) return $coords[$p1];
        if (isset($coords[$p2])) return $coords[$p2];
        if (isset($coords[$p1No])) return $coords[$p1No];
        if (isset($coords[$p2No])) return $coords[$p2No];
    }

    // 3. Keyword / substring fuzzy matching (Prioritize major known entities)
    $fuzzyKeywords = [
        'soviet' => [55.7558, 37.6173],
        'ussr' => [55.7558, 37.6173],
        'cccp' => [55.7558, 37.6173],
        'russia' => [55.7558, 37.6173],
        'roman' => [41.9028, 12.4964],
        'rome' => [41.9028, 12.4964],
        'greece' => [39.0742, 21.8243],
        'greek' => [39.0742, 21.8243],
        'croatia' => [45.1000, 15.2000],
        'hrvatska' => [45.1000, 15.2000],
        'iraq' => [33.3152, 44.3661],
        'iran' => [32.4279, 53.6880],
        'persia' => [32.4279, 53.6880],
        'britain' => [55.3781, -3.4360],
        'england' => [52.3555, -1.1743],
        'united kingdom' => [55.3781, -3.4360],
        'united states' => [37.0902, -95.7129],
        'america' => [37.0902, -95.7129],
        'bangladesh' => [23.6850, 90.3563],
        'india' => [20.5937, 78.9629],
        'pakistan' => [30.3753, 69.3451],
        'germany' => [51.1657, 10.4515],
        'deutschland' => [51.1657, 10.4515],
        'france' => [46.2276, 2.2137],
        'spain' => [40.4637, -3.7492],
        'italy' => [41.8719, 12.5674],
        'china' => [35.8617, 104.1954],
        'japan' => [36.2048, 138.2529],
        'egypt' => [26.8206, 30.8025],
        'turkey' => [38.9637, 35.2433],
        'ottoman' => [41.0082, 28.9784],
    ];

    foreach ($fuzzyKeywords as $kw => $geo) {
        if (strpos($clean, $kw) !== false || strpos($cleanNoPunct, $kw) !== false) {
            return $geo;
        }
    }

    // 4. Default fallback hash
    $hash = crc32($clean);
    $lat = (($hash % 100) / 100) * 100 - 30;
    $lng = ((($hash >> 8) % 100) / 100) * 300 - 150;
    return [$lat, $lng];
}
