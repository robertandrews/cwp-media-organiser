<?php

class CWP_Media_Item_Renderer
{
    private $media_item;
    private $current_path;
    private $preferred_path;

    public function __construct($media_item, $current_path, $preferred_path)
    {
        $this->media_item = $media_item;
        $this->current_path = $current_path;
        $this->preferred_path = $preferred_path;
    }

    public function get_template_data()
    {
        $base_data = array(
            'media_id' => $this->media_item->ID,
            'media_title' => $this->media_item->post_title,
            'media_edit_url' => get_edit_post_link($this->media_item->ID),
            'current_path' => $this->current_path,
            'preferred_path' => $this->preferred_path,
        );

        if ($this->needs_move()) {
            return array(
                'items_to_move' => array($base_data),
            );
        } elseif ($this->is_wrong_location()) {
            return array(
                'items_wrong' => array($base_data),
            );
        } else {
            return array(
                'items_correct' => array($base_data),
            );
        }
    }

    private function needs_move()
    {
        return $this->is_wrong_location() && $this->preferred_path !== null;
    }

    private function is_wrong_location()
    {
        return $this->current_path !== $this->preferred_path;
    }
}
