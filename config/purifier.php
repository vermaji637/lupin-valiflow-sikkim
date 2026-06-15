<?php
/**
 * Ok, glad you are here
 * first we get a config instance, and set the settings
 * $config = HTMLPurifier_Config::createDefault();
 * $config->set('Core.Encoding', $this->config->get('purifier.encoding'));
 * $config->set('Cache.SerializerPath', $this->config->get('purifier.cachePath'));
 * if ( ! $this->config->get('purifier.finalize')) {
 *     $config->autoFinalize = false;
 * }
 * $config->loadArray($this->getConfig());
 *
 * You must NOT delete the default settings
 * anything in settings should be compacted with params that needed to instance HTMLPurifier_Config.
 *
 * @link http://htmlpurifier.org/live/configdoc/plain.html
 */

return [
    'encoding'           => 'UTF-8',
    'finalize'           => true,
    'ignoreNonStrings'   => false,
    'cachePath'          => storage_path('app/purifier'),
    'cacheFileMode'      => 0755,
    'settings'      => [
        'default' => [
            'HTML.Doctype'             => 'HTML 4.01 Transitional',
            'HTML.Allowed'             => 'div,b,strong,i,em,u,a[href|title],ul,ol,li,p[style],br,span[style],img[width|height|alt|src]',
            'CSS.AllowedProperties'    => 'font,font-size,font-weight,font-style,font-family,text-decoration,padding-left,color,background-color,text-align',
            'AutoFormat.AutoParagraph' => true,
            'AutoFormat.RemoveEmpty'   => true,
        ],
        'test'    => [
            'Attr.EnableID' => 'true',
        ],
        "youtube" => [
            "HTML.SafeIframe"      => 'true',
            "URI.SafeIframeRegexp" => "%^(http://|https://|//)(www.youtube.com/embed/|player.vimeo.com/video/)%",
        ],
        'custom_definition' => [
            'id'  => 'html5-definitions',
            'rev' => 2,
            'debug' => false,
            'elements' => [
                // http://developers.whatwg.org/sections.html
                ['section', 'Block', 'Flow', 'Common'],
                ['nav',     'Block', 'Flow', 'Common'],
                ['article', 'Block', 'Flow', 'Common'],
                ['aside',   'Block', 'Flow', 'Common'],
                ['header',  'Block', 'Flow', 'Common'],
                ['footer',  'Block', 'Flow', 'Common'],
                       // ✅ optional for number/time
				
				// Content model actually excludes several tags, not modelled here
                ['address', 'Block', 'Flow', 'Common'],
                ['hgroup', 'Block', 'Required: h1 | h2 | h3 | h4 | h5 | h6', 'Common'],
				
				// http://developers.whatwg.org/grouping-content.html
                ['figure', 'Block', 'Optional: (figcaption, Flow) | (Flow, figcaption) | Flow', 'Common'],
                ['figcaption', 'Inline', 'Flow', 'Common'],
				
				// http://developers.whatwg.org/the-video-element.html#the-video-element
                ['video', 'Block', 'Optional: (source, Flow) | (Flow, source) | Flow', 'Common', [
                    'src' => 'URI',
					'type' => 'Text',
					'width' => 'Length',
					'height' => 'Length',
					'poster' => 'URI',
					'preload' => 'Enum#auto,metadata,none',
					'controls' => 'Bool',
                ]],
                ['source', 'Block', 'Flow', 'Common', [
					'src' => 'URI',
					'type' => 'Text',
                ]],

				// http://developers.whatwg.org/text-level-semantics.html
                ['s',    'Inline', 'Inline', 'Common'],
                ['var',  'Inline', 'Inline', 'Common'],
                ['sub',  'Inline', 'Inline', 'Common'],
                ['sup',  'Inline', 'Inline', 'Common'],
                ['mark', 'Inline', 'Inline', 'Common'],
                ['wbr',  'Inline', 'Empty', 'Core'],
				
				// http://developers.whatwg.org/edits.html
                ['ins', 'Block', 'Flow', 'Common', ['cite' => 'URI', 'datetime' => 'CDATA']],
                ['del', 'Block', 'Flow', 'Common', ['cite' => 'URI', 'datetime' => 'CDATA']],
            ],
            'attributes' => [
                ['iframe', 'allowfullscreen', 'Bool'],
                ['table', 'height', 'Text'],
                ['td', 'border', 'Text'],
                ['th', 'border', 'Text'],
                ['tr', 'width', 'Text'],
                ['tr', 'height', 'Text'],
                ['tr', 'border', 'Text'],
                   ['td', 'data-text', 'Text'],
                
                ['table', 'cellpadding', 'Text'],
                ['table', 'cellspacing', 'Text'],
                ['td', 'colspan', 'Text'],
                ['td', 'rowspan', 'Text'],
                ['th', 'colspan', 'Text'],
                ['th', 'rowspan', 'Text'],
 ['input', 'type', 'Enum#text,date,datetime-local,time,month,number,email,file,checkbox,radio'],
                   ['input', 'placeholder', 'Text'],
    ['textarea', 'placeholder', 'Text'],
    ['input', 'readonly', 'Bool'],
    ['input', 'disabled', 'Bool'],
      ['select', 'data-options', 'Text'],   // ✅ THIS FIXES YOUR ERROR
    ['td', 'data-text', 'Text'],          // (only if you still use it)
    ['div', 'contenteditable', 'Bool'], 
     ['label', 'for', 'Text'],
            ],
        ],
        'custom_attributes' => [
            ['a', 'target', 'Enum#_blank,_self,_target,_top'],
        ],
        'custom_elements' => [
            ['u', 'Inline', 'Inline', 'Common'],
        ],
        'template_body' => [
    'HTML.Doctype' => 'HTML 4.01 Transitional',
    'HTML.Forms' => true,
      'HTML.Trusted' => true, 
    'AutoFormat.AutoParagraph' => false,
    'AutoFormat.RemoveEmpty'   => false,

    'HTML.Allowed' => implode(',', [
        // ✅ Allow specific data attributes
        'div[id|class|style|contenteditable]',
                'span[id|class|style|contenteditable]',
                'p[id|class|style|contenteditable]',
                'b','strong','i','em','u','br',
                'h1[contenteditable|class|style]',
                'h2[contenteditable|class|style]',
                'h3[contenteditable|class|style]',
                'h4[contenteditable|class|style]',
                'h5[contenteditable|class|style]',
                'h6[contenteditable|class|style]',
                'ul','ol','li',

                'table[id|class|style|border|cellpadding|cellspacing]',
                'thead','tbody','tr',
                'th[colspan|rowspan|style|class|contenteditable]',
                'td[colspan|rowspan|style|class|contenteditable]',

                'img[src|alt|style|class|width|height]',

                'input[type|name|value|class|id|placeholder|style|checked|disabled|readonly|accept]',
                'select[name|class|id|style|disabled]',
                'option[value|selected]',
                'textarea[name|rows|cols|style|class|id|placeholder|disabled|readonly]',
                'button[type|class|style]', // ✅ NO onclick
                'label[for|class|style]',
                   

               
    ]),
    
'CSS.AllowTricky' => true,
    'CSS.AllowedProperties' => [
        'display',
        'border','border-collapse','width','height',
        'text-align','font-weight','margin','padding',
        'background-color','display','vertical-align',
        'font-size','font-family','color'
    ],

    'URI.AllowedSchemes' => [
        'http'  => true,
        'https' => true,
        'data' => true,
    ],
    
],
 
        
    ],

];
