<?php
/**
 * SmartStock - Shared stock for product combinations.
 *
 * @author    SmartDev
 * @copyright SmartDev
 * @license   Commercial
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Measurement units: parsing of combination labels, conversions and human readable formatting.
 */
class SmartStockUnit
{
    const FAMILY_MASS = 'mass';
    const FAMILY_VOLUME = 'volume';
    const FAMILY_LENGTH = 'length';
    const FAMILY_PIECE = 'piece';

    /** Canonical units: family and size expressed in the base unit of the family */
    const UNITS = [
        'mg' => [self::FAMILY_MASS, 0.001],
        'g' => [self::FAMILY_MASS, 1],
        'kg' => [self::FAMILY_MASS, 1000],
        'ml' => [self::FAMILY_VOLUME, 1],
        'cl' => [self::FAMILY_VOLUME, 10],
        'dl' => [self::FAMILY_VOLUME, 100],
        'l' => [self::FAMILY_VOLUME, 1000],
        'mm' => [self::FAMILY_LENGTH, 0.1],
        'cm' => [self::FAMILY_LENGTH, 1],
        'm' => [self::FAMILY_LENGTH, 100],
        'pc' => [self::FAMILY_PIECE, 1],
    ];

    const ALIASES = [
        'gr' => 'g', 'grs' => 'g', 'gramme' => 'g', 'grammes' => 'g', 'gram' => 'g', 'grams' => 'g',
        'kgs' => 'kg', 'kilo' => 'kg', 'kilos' => 'kg', 'kilogramme' => 'kg', 'kilogrammes' => 'kg',
        'lt' => 'l', 'litre' => 'l', 'litres' => 'l', 'liter' => 'l', 'liters' => 'l',
        'metre' => 'm', 'metres' => 'm', 'mètre' => 'm', 'mètres' => 'm', 'meter' => 'm', 'meters' => 'm',
        'pcs' => 'pc', 'piece' => 'pc', 'pieces' => 'pc', 'pièce' => 'pc', 'pièces' => 'pc',
        'u' => 'pc', 'unite' => 'pc', 'unites' => 'pc', 'unité' => 'pc', 'unités' => 'pc', 'unit' => 'pc', 'units' => 'pc',
    ];

    /** Unit used to store the shared stock of each family */
    const FAMILY_BASE_UNITS = [
        self::FAMILY_MASS => 'g',
        self::FAMILY_VOLUME => 'ml',
        self::FAMILY_LENGTH => 'cm',
        self::FAMILY_PIECE => 'pc',
    ];

    /** Unit used to compare prices of each family (price per kg, per litre...) */
    const FAMILY_REFERENCE_UNITS = [
        self::FAMILY_MASS => 'kg',
        self::FAMILY_VOLUME => 'l',
        self::FAMILY_LENGTH => 'm',
        self::FAMILY_PIECE => 'pc',
    ];

    /** Larger unit used for display once a quantity reaches its size */
    const DISPLAY_UPGRADES = [
        'g' => 'kg',
        'ml' => 'l',
        'cl' => 'l',
        'cm' => 'm',
        'mm' => 'm',
    ];

    const PIECE_KEYWORDS_PATTERN = '/(lot|pack|box|boite|boîte|coffret|paquet|set|pi[eè]ces?|pcs|unit[eé]s?)|x\s*\d|\d\s*x(?!\p{L})/iu';

    /**
     * Returns the canonical key of a unit label ("Grammes" gives "g"), or null for an unknown unit.
     */
    public static function normalize(string $unitLabel): ?string
    {
        $lowerLabel = Tools::strtolower(trim($unitLabel));
        if (isset(self::UNITS[$lowerLabel])) {
            return $lowerLabel;
        }
        return isset(self::ALIASES[$lowerLabel]) ? self::ALIASES[$lowerLabel] : null;
    }

    public static function getFamily(string $unitLabel): ?string
    {
        $canonicalUnit = self::normalize($unitLabel);
        return $canonicalUnit === null ? null : self::UNITS[$canonicalUnit][0];
    }

    /**
     * Extracts every "number + optional unit" pair of a label ("Pack 2 x 250 g" gives [2, x], [250, g]).
     * The multiplier flag marks a number followed by "x", as in "2 x 250 g".
     *
     * @return array<int, array{value: float, unit: string|null, multiplier: bool}>
     */
    public static function parseQuantities(string $label): array
    {
        preg_match_all('/(\d+(?:[.,]\d+)?)\s*(\p{L}+)?/u', $label, $quantityMatches, PREG_SET_ORDER);
        $parsedQuantities = [];
        foreach ($quantityMatches as $quantityMatch) {
            $rawUnit = isset($quantityMatch[2]) ? $quantityMatch[2] : '';
            $parsedQuantities[] = [
                'value' => (float) str_replace(',', '.', $quantityMatch[1]),
                'unit' => $rawUnit !== '' ? self::normalize($rawUnit) : null,
                'multiplier' => Tools::strtolower($rawUnit) === 'x',
            ];
        }
        return $parsedQuantities;
    }

    /**
     * Base units consumed by one sale of a combination, guessed from its label.
     * The first quantity of the same family as the base unit wins ("1 kg" gives 1000 with a base unit "g"),
     * otherwise the first number found, otherwise 0 (own stock).
     */
    public static function suggestRatio(string $label, string $baseUnit): int
    {
        $parsedQuantities = self::parseQuantities($label);
        if (empty($parsedQuantities)) {
            return 0;
        }
        $canonicalBaseUnit = self::normalize($baseUnit);
        if ($canonicalBaseUnit !== null) {
            $baseFamily = self::UNITS[$canonicalBaseUnit][0];
            $multiplier = 1.0;
            $familyQuantity = null;
            foreach ($parsedQuantities as $parsedQuantity) {
                if ($parsedQuantity['unit'] !== null && self::UNITS[$parsedQuantity['unit']][0] === $baseFamily) {
                    $familyQuantity = $parsedQuantity;
                    break;
                }
                if ($parsedQuantity['multiplier']) {
                    $multiplier *= $parsedQuantity['value'];
                }
            }
            if ($familyQuantity !== null) {
                $sizeInBaseUnit = $familyQuantity['value'] * self::UNITS[$familyQuantity['unit']][1] / self::UNITS[$canonicalBaseUnit][1];
                return max(0, (int) round($sizeInBaseUnit * $multiplier));
            }
        }
        return max(0, (int) round($parsedQuantities[0]['value']));
    }

    /**
     * Detects the most relevant base unit for a set of combination labels, or null when no unit can be inferred.
     *
     * @param string[] $labels
     */
    public static function detectBaseUnit(array $labels): ?string
    {
        $familyVotes = [];
        $pieceVotes = 0;
        foreach ($labels as $label) {
            foreach (self::parseQuantities($label) as $parsedQuantity) {
                if ($parsedQuantity['unit'] !== null) {
                    $family = self::UNITS[$parsedQuantity['unit']][0];
                    $familyVotes[$family] = (isset($familyVotes[$family]) ? $familyVotes[$family] : 0) + 1;
                    continue 2;
                }
            }
            if (preg_match(self::PIECE_KEYWORDS_PATTERN, $label) && preg_match('/\d/', $label)) {
                ++$pieceVotes;
            }
        }
        if (!empty($familyVotes)) {
            arsort($familyVotes);
            return self::FAMILY_BASE_UNITS[(string) key($familyVotes)];
        }
        return $pieceVotes > 0 ? self::FAMILY_BASE_UNITS[self::FAMILY_PIECE] : null;
    }

    /**
     * Expresses a quantity of base units in the most readable unit ("1500 g" gives "1.5 kg").
     *
     * @return array{value: float, unit: string}
     */
    public static function toReadableQuantity(int $quantity, string $baseUnit): array
    {
        $canonicalBaseUnit = self::normalize($baseUnit);
        if ($canonicalBaseUnit === null || !isset(self::DISPLAY_UPGRADES[$canonicalBaseUnit])) {
            return ['value' => (float) $quantity, 'unit' => $baseUnit];
        }
        $upgradedUnit = self::DISPLAY_UPGRADES[$canonicalBaseUnit];
        $upgradeFactor = self::UNITS[$upgradedUnit][1] / self::UNITS[$canonicalBaseUnit][1];
        if (abs($quantity) < $upgradeFactor) {
            return ['value' => (float) $quantity, 'unit' => $baseUnit];
        }
        return ['value' => round($quantity / $upgradeFactor, 3), 'unit' => $upgradedUnit];
    }

    /**
     * Unit used to compare prices and its size in base units ("g" gives ["kg", 1000]).
     *
     * @return array{unit: string, size: float}
     */
    public static function getReferenceUnit(string $baseUnit): array
    {
        $canonicalBaseUnit = self::normalize($baseUnit);
        if ($canonicalBaseUnit === null) {
            return ['unit' => $baseUnit, 'size' => 1.0];
        }
        $referenceUnit = self::FAMILY_REFERENCE_UNITS[self::UNITS[$canonicalBaseUnit][0]];
        return ['unit' => $referenceUnit, 'size' => (float) (self::UNITS[$referenceUnit][1] / self::UNITS[$canonicalBaseUnit][1])];
    }

    /**
     * Human readable quantity in the context language ("1500" with unit "g" gives "1,5 kg" in French).
     */
    public static function formatQuantity(int $quantity, string $baseUnit): string
    {
        $readableQuantity = self::toReadableQuantity($quantity, $baseUnit);
        return trim(self::formatNumber($readableQuantity['value']) . ' ' . $readableQuantity['unit']);
    }

    /**
     * Formats a number with the separators of the context locale, without useless decimals.
     */
    public static function formatNumber(float $number): string
    {
        $context = Context::getContext();
        $locale = method_exists($context, 'getCurrentLocale') ? $context->getCurrentLocale() : null;
        if ($locale !== null) {
            return (string) $locale->formatNumber($number);
        }
        $decimalCount = abs($number - round($number)) < 0.0005 ? 0 : 3;
        $formattedNumber = number_format($number, $decimalCount, ',', ' ');
        return $decimalCount > 0 ? rtrim(rtrim($formattedNumber, '0'), ',') : $formattedNumber;
    }
}
