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
 * Explicit change requested on a pool during a reconciliation: absolute value (inventory) and/or signed adjustment.
 */
class SmartStockPoolOperation
{
    /** @var int|null */
    private $absoluteQuantity;

    /** @var int */
    private $adjustment;

    /** @var string */
    private $comment;

    private function __construct(?int $absoluteQuantity, int $adjustment, string $comment)
    {
        $this->absoluteQuantity = $absoluteQuantity;
        $this->adjustment = $adjustment;
        $this->comment = $comment;
    }

    /**
     * Plain reconciliation, without any explicit change.
     */
    public static function none(): self
    {
        return new self(null, 0, '');
    }

    public static function inventory(int $absoluteQuantity, string $comment = ''): self
    {
        return new self($absoluteQuantity, 0, $comment);
    }

    public static function adjustment(int $adjustment, string $comment = ''): self
    {
        return new self(null, $adjustment, $comment);
    }

    public static function fromOptionalValues(?int $absoluteQuantity, int $adjustment, string $comment = ''): self
    {
        return new self($absoluteQuantity, $adjustment, $comment);
    }

    public function getAbsoluteQuantity(): ?int
    {
        return $this->absoluteQuantity;
    }

    public function getAdjustment(): int
    {
        return $this->adjustment;
    }

    public function getComment(): string
    {
        return $this->comment;
    }

    public function isEmpty(): bool
    {
        return $this->absoluteQuantity === null && $this->adjustment === 0;
    }
}
