<?php
declare(strict_types=1);

/**
 * Federal agency / sub-agency resolution from an award's ALN (CFDA) prefix + the
 * assistance-listing agency_code. Single source of truth shared by the grantee
 * profile (Federal Footprint, per-finding badges) and the finding-detail route.
 *
 * HHS (prefix 93) breaks out to its operating/staff divisions via agency_code;
 * every other agency maps from its 2-digit ALN prefix.
 */
class Agency
{
    // HHS operating/staff divisions: agency_code -> [acronym, canonical name].
    public const HHS_SUB = [
        '7501' => ['OS', 'Office of the Secretary'],
        '7504' => ['OIG', 'Office of Inspector General'],
        '7505' => ['ASPR', 'Administration for Strategic Preparedness and Response'],
        '7521' => ['OASH', 'Office of the Assistant Secretary for Health'],
        '7522' => ['SAMHSA', 'Substance Abuse and Mental Health Services Administration'],
        '7523' => ['CDC', 'Centers for Disease Control and Prevention'],
        '7524' => ['FDA', 'Food and Drug Administration'],
        '7526' => ['HRSA', 'Health Resources and Services Administration'],
        '7527' => ['IHS', 'Indian Health Service'],
        '7528' => ['AHRQ', 'Agency for Healthcare Research and Quality'],
        '7529' => ['NIH', 'National Institutes of Health'],
        '7530' => ['CMS', 'Centers for Medicare & Medicaid Services'],
        '7570' => ['ASA', 'Office of the Assistant Secretary for Administration'],
        '7577' => ['ACL', 'Administration for Community Living'],
        '7590' => ['ACF', 'Administration for Children and Families'],
    ];

    // top-level agency acronym by ALN prefix (HHS uses its operating division via HHS_SUB)
    public const PREFIX_ACR = ['10' => 'USDA', '11' => 'DOC', '12' => 'DOD', '14' => 'HUD', '15' => 'DOI', '16' => 'DOJ',
        '17' => 'DOL', '19' => 'State', '20' => 'DOT', '21' => 'Treasury', '23' => 'ARC', '45' => 'NEA/NEH',
        '47' => 'NSF', '59' => 'SBA', '64' => 'VA', '66' => 'EPA', '81' => 'DOE', '84' => 'ED', '93' => 'HHS',
        '94' => 'AmeriCorps', '96' => 'SSA', '97' => 'DHS', '98' => 'USAID',
        '30' => 'EEOC', '31' => 'EXIM', '32' => 'FCC', '38' => 'FFIEC', '39' => 'GSA', '42' => 'LOC',
        '43' => 'NASA', '44' => 'NCUA', '54' => 'ODNI', '57' => 'RRB', '77' => 'NRC', '85' => 'Udall',
        '86' => 'PBGC', '87' => 'DFC', '89' => 'NARA', '90' => 'USAGM', '91' => 'USIP', '92' => 'NCD', '95' => 'EOP'];

    /** Short badge acronym (HHS operating division when prefix 93, else top-level agency). */
    public static function acr(?string $prefix, ?string $code): string
    {
        if ($prefix === '93' && $code !== null && isset(self::HHS_SUB[$code])) return self::HHS_SUB[$code][0];
        return self::PREFIX_ACR[$prefix] ?? (string) $prefix;
    }

    /** Full sub-agency name. HHS division name for prefix 93; else the assistance-listing
     *  agency name (title-cased) when given, falling back to the top-level acronym. */
    public static function name(?string $prefix, ?string $code, ?string $agencyName = null): ?string
    {
        if ($prefix === '93' && $code !== null && isset(self::HHS_SUB[$code])) return self::HHS_SUB[$code][1];
        if ($agencyName !== null && $agencyName !== '') return ucwords(strtolower($agencyName));
        return self::PREFIX_ACR[$prefix] ?? null;
    }

    /** HHS divisions get the distinct (blue) badge in the UI; everything else the amber one. */
    public static function isHhs(?string $prefix): bool { return $prefix === '93'; }
}
