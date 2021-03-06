<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Util;

use Composer\Json\JsonFile;

/**
 * Supports composer array and SPDX tag notation for disjunctive/conjunctive
 * licenses.
 *
 * @author Tom Klingenberg <tklingenberg@lastflood.net>
 */
class SpdxLicense
{
    /**
     * @var array
     */
    private $licenses;

    public function __construct()
    {
        $this->loadLicenses();
    }

    private function loadLicenses()
    {
        if (is_array($this->licenses)) {
            return $this->licenses;
        }

        $jsonFile = new JsonFile(__DIR__ . '/../../../res/spdx-licenses.json');
        $this->licenses = $jsonFile->read();

        return $this->licenses;
    }

    /**
     * Returns license metadata by license identifier.
     *
     * @param string $identifier
     *
     * @return array|null
     */
    public function getLicenseByIdentifier($identifier)
    {
        if (!isset($this->licenses[$identifier])) {
            return;
        }

        $license = $this->licenses[$identifier];

        // add URL for the license text (it's not included in the json)
        $license[2] = 'http://spdx.org/licenses/' . $identifier . '#licenseText';

        return $license;
    }

    /**
     * Returns the short identifier of a license by full name.
     *
     * @param string $identifier
     *
     * @return string
     */
    public function getIdentifierByName($name)
    {
        foreach ($this->licenses as $identifier => $licenseData) {
            if ($licenseData[0] === $name) { // key 0 = fullname
                return $identifier;
            }
        }
    }

    /**
     * Returns the OSI Approved status for a license by identifier.
     *
     * @return bool
     */
    public function isOsiApprovedByIdentifier($identifier)
    {
        return $this->licenses[$identifier][1]; // key 1 = osi approved
    }

    /**
     * Check, if the identifier for a license is valid.
     *
     * @param string $identifier
     *
     * @return bool
     */
    private function isValidLicenseIdentifier($identifier)
    {
        $identifiers = array_keys($this->licenses);

        return in_array($identifier, $identifiers);
    }

    /**
     * @param array|string $license
     *
     * @return bool
     * @throws \InvalidArgumentException
     */
    public function validate($license)
    {
        if (is_array($license)) {
            $count = count($license);
            if ($count !== count(array_filter($license, 'is_string'))) {
                throw new \InvalidArgumentException('Array of strings expected.');
            }
            $license = $count > 1  ? '('.implode(' or ', $license).')' : (string) reset($license);
        }

        if (!is_string($license)) {
            throw new \InvalidArgumentException(sprintf(
                'Array or String expected, %s given.', gettype($license)
            ));
        }

        return $this->isValidLicenseString($license);
    }

    /**
     * @param string $license
     *
     * @return bool
     * @throws \RuntimeException
     */
    private function isValidLicenseString($license)
    {
        $tokens = array(
            'po' => '\(',
            'pc' => '\)',
            'op' => '(?:or|and)',
            'lix' => '(?:NONE|NOASSERTION)',
            'lir' => 'LicenseRef-\d+',
            'lic' => '[-+_.a-zA-Z0-9]{3,}',
            'ws' => '\s+',
            '_' => '.',
        );

        $next = function () use ($license, $tokens) {
            static $offset = 0;

            if ($offset >= strlen($license)) {
                return null;
            }

            foreach ($tokens as $name => $token) {
                if (false === $r = preg_match('{' . $token . '}', $license, $matches, PREG_OFFSET_CAPTURE, $offset)) {
                    throw new \RuntimeException('Pattern for token %s failed (regex error).', $name);
                }
                if ($r === 0) {
                    continue;
                }
                if ($matches[0][1] !== $offset) {
                    continue;
                }
                $offset += strlen($matches[0][0]);

                return array($name, $matches[0][0]);
            }

            throw new \RuntimeException('At least the last pattern needs to match, but it did not (dot-match-all is missing?).');
        };

        $open = 0;
        $require = 1;
        $lastop = null;

        while (list($token, $string) = $next()) {
            switch ($token) {
                case 'po':
                    if ($open || !$require) {
                        return false;
                    }
                    $open = 1;
                    break;
                case 'pc':
                    if ($open !== 1 || $require || !$lastop) {
                        return false;
                    }
                    $open = 2;
                    break;
                case 'op':
                    if ($require || !$open) {
                        return false;
                    }
                    $lastop || $lastop = $string;
                    if ($lastop !== $string) {
                        return false;
                    }
                    $require = 1;
                    break;
                case 'lix':
                    if ($open) {
                        return false;
                    }
                    goto lir;
                case 'lic':
                    if (!$this->isValidLicenseIdentifier($string)) {
                        return false;
                    }
                    // Fall-through intended
                case 'lir':
                    lir:
                    if (!$require) {
                        return false;
                    }
                    $require = 0;
                    break;
                case 'ws':
                    break;
                case '_':
                    return false;
                default:
                    throw new \RuntimeException(sprintf('Unparsed token: %s.', print_r($token, true)));
            }
        }

        return !($open % 2 || $require);
    }
}
