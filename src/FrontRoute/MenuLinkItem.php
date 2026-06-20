<?php
namespace VonNeumannGame\FrontRoute;

class MenuLinkItem {
    private string $title;
    private string $href;
    private bool $active;
    private bool $external;

    public function __construct(string $title, string $href, bool $active = false, bool $external = false)
    {
        $this->title = $title;
        $this->href = $href;
        $this->active = $active;
        $this->external = $external;
    }
    public function getTitle(): string
    {
        return $this->title;
    }
    public function getHref(): string
    {
        return $this->href;
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
