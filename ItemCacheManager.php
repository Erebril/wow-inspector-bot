<?php

class ItemCacheManager {
    private $cacheFile = __DIR__ . '/items_db.json';
    private $items = [];

    public function __construct() {
        if (file_exists($this->cacheFile)) {
            $this->items = json_decode(file_get_contents($this->cacheFile), true) ?: [];
        }
    }

    public function getItem($itemId) {
        return $this->items[$itemId] ?? null;
    }

    public function saveItem($itemId, $data) {
        $this->items[$itemId] = [
            'name' => $data['name'],
            'level' => $data['level'],
            'quality' => $data['quality']['type'],
            'inventory_type' => $data['inventory_type']['type']
        ];
        file_put_contents($this->cacheFile, json_encode($this->items, JSON_PRETTY_PRINT));
    }
}