<?php
namespace VonNeumannGame\FrontRoute;

class MenuLinkItem {
    private string $title;
    private string $href;
    private bool $active;

    public function __construct(string $title, string $href, bool $active = false)
    {
        $this->title = $title;
        $this->href = $href;
        $this->active = $active;
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

}