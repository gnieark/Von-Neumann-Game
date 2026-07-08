<?php
namespace VonNeumannGame\FrontRoute;

class MenuLinkItem {
    private string $title;
    private string $href;
    private bool $active;
    private bool $external;
    private string $baseHref;

    public function __construct(string $title, string $href, bool $active = false, bool $external = false, ?string $baseHref = null)
    {
        $this->title = $title;
        $this->href = $href;
        $this->active = $active;
        $this->external = $external;
        $this->baseHref = $baseHref ?? $href;
    }
    public function getTitle(): string
    {
        return $this->title;
    }
    public function getHref(): string
    {
        return $this->href;
    }
    public function getBaseHref(): string
    {
        return $this->baseHref;
    }
    public function setHref(string $href): void
    {
        $this->href = $href;
    }
    public function isActive(): bool
    {        
        return $this->active;
    }   
    public function isExternal(): bool
    {
        return $this->external;
    }

}
