<?php
/**
 * This file is part of Phplrt package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace Phplrt\Ast;

use Phplrt\Ast\Dumper\HoaDumper;
use Phplrt\Ast\Dumper\XmlDumper;

/**
 * Class Node
 */
abstract class Node implements NodeInterface
{
    /**
     * @var string
     */
    protected $name;

    /**
     * @var int
     */
    protected $offset;

    /**
     * Node constructor.
     *
     * @param string $name
     * @param int $offset
     */
    public function __construct(string $name, int $offset = 0)
    {
        $this->name = $name;
        $this->offset = $offset;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return int
     */
    public function getOffset(): int
    {
        return $this->offset;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        switch (true) {
            case \class_exists(XmlDumper::class) &&
                 \class_exists(\DOMDocument::class):
                return (new XmlDumper())->dump($this);

            case \class_exists(HoaDumper::class):
                return (new HoaDumper())->dump($this);
        }

        return $this->getName();
    }
}
