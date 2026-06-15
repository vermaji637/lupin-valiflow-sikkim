<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Str;

class TemplateController extends Controller
{

    public function save(Request $request, $form_key)
    {
        $schema = json_decode($request->schema_json, true);

        // ✅ CALL THE FIX HERE
        $schema = $this->normalizeSchemaKeys($schema);

        // Save logic here...
    }

    // ==============================
    // ✅ PASTE PRIVATE FUNCTIONS HERE
    // ==============================

    private function normalizeSchemaKeys(array $schema)
    {
        if (!isset($schema['form']['blocks'])) {
            return $schema;
        }

        foreach ($schema['form']['blocks'] as $i => &$block) {

            if (empty($block['key'])) {
                $block['key'] = $this->makeStableKey($block, $i);
            }

            $block = $this->normalizeNode($block);
        }

        return $schema;
    }

    private function normalizeNode(array $node)
    {
        if (!empty($node['rows']) && is_array($node['rows'])) {
            foreach ($node['rows'] as &$row) {
                if (!empty($row['cells']) && is_array($row['cells'])) {
                    foreach ($row['cells'] as &$cell) {
                        if (!empty($cell['content']) && is_array($cell['content'])) {
                            foreach ($cell['content'] as &$child) {
                                $child = $this->normalizeNode($child);
                            }
                        }
                    }
                }
            }
        }

        if (!empty($node['content']) && is_array($node['content'])) {
            foreach ($node['content'] as &$child) {
                $child = $this->normalizeNode($child);
            }
        }

        if (!empty($node['items']) && is_array($node['items'])) {
            foreach ($node['items'] as &$child) {
                if (is_array($child)) {
                    $child = $this->normalizeNode($child);
                }
            }
        }

        return $node;
    }

    private function makeStableKey(array $block, int $index)
    {
        if (!empty($block['text'])) {
            return \Str::slug($block['text']);
        }

        if (!empty($block['type'])) {
            return $block['type'] . '_' . $index;
        }

        return 'block_' . $index;
    }

} // ✅ DO NOT ADD CODE AFTER THIS
