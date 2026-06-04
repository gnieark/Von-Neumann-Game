<?php

declare(strict_types=1);

namespace VonNeumannGame\View;

/**
 * The TplBlock class.
 *
 * @category Template
 * @package  TplBlock
 * @author   gnieark <gnieark@tinad.fr>
 * @license  GNU General Public License V3
 * @link     https://github.com/gnieark/tplBlock/
 */
class TplBlock
{
    const BLOCKSTARTSTART = '<!--\s+BEGIN\s+';
    const BLOCKSTARTEND = '\s+-->';
    const BLOCKENDSTART = '<!--\s+END\s+';
    const BLOCKENDEND = '\s+-->';
    const STARTENCLOSURE = '{{';
    const ENDENCLOSURE = '}}';

    public $name = '';

    private $vars = [];
    private $subBlocs = [];
    private $unusedRegex = "";
    private $trim = true;
    private $replaceNonGivenVars = true;
    private $strictMode = true;

    public function __construct($name = "")
    {
        if ($name !== "" and ! ctype_alnum($name)) {
            throw new \UnexpectedValueException(
                "Only alpha-numerics chars are allowed on the block name"
            );
        }

        $this->name = $name;
        $this->unusedRegex = '/'
                           . self::BLOCKSTARTSTART
                           . ' *([a-z][a-z0-9.]*) *'
                           . self::BLOCKSTARTEND
                           . '(.*?)'
                           . self::BLOCKENDSTART
                           . ' *\1 *'
                           . self::BLOCKENDEND
                           . '/is';
    }

    public function addVars(array $vars) :TplBlock
    {
        $this->vars = array_merge($this->vars, $vars);
        return $this;
    }

    public function addPrefixedVars(string $prefix, array $vars) :TplBlock
    {
        $prefixed = [];
        foreach ($vars as $key => $value) {
            $prefixed[$prefix . '.' . $key] = $value;
        }

        return $this->addVars($prefixed);
    }

    public function addSubBlock(TplBlock $bloc) :TplBlock
    {
        if ($bloc->name === "") {
            throw new \UnexpectedValueException(
                "A sub tpl block can't have an empty name"
            );
        }

        $this->subBlocs[$bloc->name][] = $bloc;

        return $this;
    }

    public static function is_assoc($arr) :bool
    {
        if (!is_array($arr)) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    public function addSubBlocsDefinitions($subBlocsDefinitions):TplBlock
    {
        foreach ($subBlocsDefinitions as $itemKey => $itemValue) {
            if (self::is_assoc($itemValue)) {
                $subBloc = new TplBlock($itemKey);
                $subBloc->addSubBlocsDefinitions($itemValue);
                $this->addSubBlock($subBloc);
            } elseif (is_array($itemValue)) {
                foreach ($itemValue as $subItem) {
                    $subBloc = new TplBlock($itemKey);
                    $subBloc->addSubBlocsDefinitions($subItem);
                    $this->addSubBlock($subBloc);
                }
            } else {
                $this->addVars(array($itemKey => $itemValue));
            }
        }

        return $this;
    }

    private function subBlockRegex(string $prefix, string $blocName) :string
    {
        return '/'
             . self::BLOCKSTARTSTART
             . preg_quote($prefix . $blocName)
             . self::BLOCKSTARTEND
             . ($this->trim === false ? '' : '(?:\R|)?' )
             . '(.*?)'
             . ($this->trim === false ?  '' : '(?:\R|)?' )
             . self::BLOCKENDSTART
             . preg_quote($prefix . $blocName)
             . self::BLOCKENDEND
             . '/is';
    }

    public function applyTplStr(string $str, string $subBlocsPath = ""):string
    {
        $prefix = $subBlocsPath === "" ? "" : $subBlocsPath . ".";

        foreach ($this->vars as $key => $value) {
            $str = str_replace(
                self::STARTENCLOSURE . $prefix . $key . self::ENDENCLOSURE,
                $value,
                $str
            );
        }

        foreach ($this->subBlocs as $blocName => $blocsArr) {
            $str = preg_replace_callback(
                $this->subBlockRegex($prefix, $blocName),
                function ($m) use ($blocName, $blocsArr, $prefix) {
                    $out = "";
                    foreach ($blocsArr as $bloc) {
                        $out .= $bloc->applyTplStr(
                            $m[1],
                            $prefix . $blocName
                        );
                    }

                    return $out;
                },
                $str
            );
        }

        $str = preg_replace($this->unusedRegex, "", $str);

        if ($this->replaceNonGivenVars) {
            $str = preg_replace("/" . self::STARTENCLOSURE . '([a-z][a-z0-9.]*)' . self::ENDENCLOSURE . "/", '', $str);
        }

        if (($this->strictMode)
            && (
                   preg_match("/" . self::BLOCKSTARTSTART . "/", $str)
                || preg_match("/" . self::BLOCKENDSTART . "/", $str)
            )
        ) {
            throw new \UnexpectedValueException("Template string not consistent");
        }

        return $str;
    }

    public function applyTplFile(string $file) :string
    {
        if (! $tplStr = file_get_contents($file)) {
            throw new \UnexpectedValueException("Cannot read given file $file");
        }

        return $this->applyTplStr($tplStr, "");
    }

    public function doTrim() :TplBlock
    {
        $this->trim = true;
        return $this;
    }

    public function dontTrim() :TplBlock
    {
        $this->trim = false;
        return $this;
    }

    public function doReplaceNonGivenVars() :TplBlock
    {
        $this->replaceNonGivenVars = true;
        return $this;
    }

    public function dontReplaceNonGivenVars() :TplBlock
    {
        $this->replaceNonGivenVars = false;
        return $this;
    }

    public function doStrictMode() :TplBlock
    {
        $this->strictMode = true;
        return $this;
    }

    public function dontStrictMode() :TplBlock
    {
        $this->strictMode = false;
        return $this;
    }
}
