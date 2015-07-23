<?php 

/**
* Site Sections
* -----------------------------------------------------------------------------
* The eight sections of the Tuairisc site, with a ninth fallback class for
* every other thing that does not fit neatly into these. The order of these 
* elements is important, because this is the order in which the menu is output.
* 
* @category   PHP Script
* @package    Tuairisc.ie
 * @author     Mark Grealish <mark@bhalash.com>
 * @copyright  Copyright (c) 2014-2015, Tuairisc Bheo Teo
 * @license    https://www.gnu.org/copyleft/gpl.html The GNU General Public License v3.0
 * @version    2.0
 * @link       https://github.com/bhalash/tuairisc.ie
 *
 * This file is part of Tuairisc.ie.
 * 
 * Tuairisc.ie is free software: you can redistribute it and/or modify it under the
 * terms of the GNU General Public License as published by the Free Software 
 * Foundation, either version 3 of the License, or (at your option) any later
 * version.
 * 
 * Tuairisc.ie is distributed in the hope that it will be useful, but WITHOUT ANY 
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR
 * A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License along with 
 * Tuairisc.ie. If not, see <http://www.gnu.org/licenses/>.
 */ 

class Section_Manager {
    static $instantiated = false;

    // Current site section.
    public static $section;

    private static $keys = array(
        // Keys for saved options.
        'sections' => 'section_manager_sections',
        'menus' => 'section_manager_menus'
    );

    private static $errors = array(
        'instance' => 'Error: Section Manager can only be instantiated once.',
        'empty' => 'Error: An array of categories must be passed to Section Manager.',
        'not_category' => '"%s" is not a valid category and will be skipped',
        'is_child' => '"%s" is a child category and will be skipped'
    );

    // Fallback category ID;
    private static $fallback_id = 999;

    public function __construct($categories) {
        if (self::$instantiated) {
            // More than one running instance can lead to strange things.
            throw new Exception(self::$errors['instance']);
        }
        
        self::$instantiated = true;

        if (!is_array($categories) || empty($categories)) {
            throw new Exception(self::$errors['empty']);
        }

        $sections = get_option(self::$keys['sections']);

        if ($categories['categories'] !== $sections['id'] || $categories['home'] !== $sections['home'] || WP_DEBUG) {
            // Update generated options and menu if sctions have changed.
            $this->option_setup($categories);
            $this->menu_setup();
        }

        add_action('wp_head', array($this, 'set_current_section'));
        add_filter('body_class', array($this, 'set_section_body_class'));
    }

    /**
     * Determine Current Section
     * -------------------------------------------------------------------------
     * The site is segmented into n primary sections. This generates the
     * WordPress options for the site sections if the array of sections changes.
     * 
     * @param   array       $categories         Structured array of categories.
     * @return  array       $sections           Parsed array of sections.
     */

    private function option_setup($categories = null) {
        $sections = array(
            'id' => array(),
            'section' => array()
        );

        foreach ($categories['categories'] as $cat) {
            /* Passed int must resolve to a site category.
             * Category must be a top-level parent category. */
            $category = get_category($cat);

            if (!$category) {
                trigger_error(sprintf(self::$errors['not_category'], $cat), E_USER_WARNING);
                continue;
            }

            if ($category->category_parent) {
                trigger_error(sprintf(self::$errors['is_child'], $cat), E_USER_WARNING);
                continue;
            }

            $sections['id'][] = $category->cat_ID;
            $sections['section'][$category->cat_ID] = $category->slug;
        }

        // If home is set, use it, otherwise, pick first passed category.

        if (isset($categories['home']) && get_category($categories['home'])) {
            $sections['home']['id'] = $categories['home'];
            $sections['home']['slug'] = $this->get_section_slug($categories['home']);
        } else {
            $sections['home']['id'] = $sections['id'][0];
            $sections['home']['slug'] = $this->get_section_slug($sections['id'][0]);
        }

        update_option(self::$keys['sections'], $sections);
        return $sections;
    }

    /**
     * Determine Current Section on Page Load
     * -------------------------------------------------------------------------
     * Get the ID of the section of the site. The site is broken into sections 
     * defined by category-one each for major categories, with a final fallback 
     * section for everything else.
     * 
     * Explicit flag for 404: Modern browsers try several fallback methods if 
     * an asset fails to fetch, or so it appears to me. This can cause them to
     * spam and cause wp_head to trigger muptiple times.
     */

    public function set_current_section() {
        $sections = get_option(self::$keys['sections']);

        /* Temporary (probably). The client has not decided how to handle
         * non-section parts of the site, so it is all home for now. */
        $current_id = $sections['home']['id'];

        if (!isset(self::$section) || !self::$section && !is_404()) {
            if (is_home() || is_front_page()) {
                // 1. If home page or main index, default to first item.
                $current_id = $sections['home']['id'];
            } else if (is_category() || is_single()) {

                // 2. Else set categroy by the parent category.
                if (is_category()) {
                    $category = get_query_var('cat');
                } else if (is_single()) {
                    $category = get_the_category($post->ID)[0]->cat_ID;
                }

                $category = $this->category_parent_id($category);

                if ($this->is_section_category($category)) {
                    $current_id = $category;
                }
            } 

            // 3. Add more sections here if they need to be evaluated.
        }

        self::$section = array(
            'id' => $current_id,
            'slug' => $this->get_section_slug($current_id)
        );
    }

    /**
     * Is Current Category a Section?
     * -------------------------------------------------------------------------
     * Only matches top-level categories, as it is used in circumstances where 
     * parent has been already determined. Matches category ID against stored
     * section IDs.
     * 
     * @param   object/id       $category       Category ID or object.
     * @return  bool                            Category is section, true/false.
     */

    private function is_section_category($category) {
        $category = get_category($category);
        $key = self::$keys['sections'];

        if (!$category) {
            return false;
        }
        
        return (in_array($category->cat_ID, get_option($key)['id']));
    }

    /**
     * Get ID of Category's Ultimate Parent
     * -------------------------------------------------------------------------
     * The site is sectioned into several parent categories with children and 
     * grandchildren beneath. Recursively ascend through parent categories
     * until you hit the top.
     * 
     * @param   int     $cat_id          Category ID
     * @param   int     $cat_id          ID of original category's parent.
     */

    private function category_parent_id($cat_id = null) {
        $category = get_category($cat_id);

        if ($category->category_parent) {
            $cat_id = $this->category_parent_id($category->category_parent);
        }

        return $cat_id;
    }

    /**
     * Get Category Children IDs
     * -------------------------------------------------------------------------
     * Return array of all category childen categories.
     *
     * @param   int/object      $category      The category.
     * @return  array           $children      Array of category children IDs.
     */

    private function category_children($category) {
        $category = get_category($category);
        $children = array();

        $categories = get_categories(array('child_of' => $category->cat_ID));

        if ($categories) {
            foreach($categories as $cat) {
                $children[] = $cat->cat_ID;
            }
        }

        return $children;
    }

    /**
     * Get Section Slug
     * -------------------------------------------------------------------------
     * @param   object/id       $category       Category ID or object.
     * @return  string          $slog           Slug for current section.
     */

    private function get_section_slug($category) {
        $slug = 'other';

        /* Since '999' doesn't exist as a section, going to a tag or search
         * would cause a warning to be thrown since it comes up '999'. */

        if ($category && $category !== self::$fallback_id) {
            $option = get_option(self::$keys['sections'])['section'][$category];

            if ($option) {
                $slug = $option;
            }
        }

        return $slug;
    }

    /**
     * Set Body Classes
     * -------------------------------------------------------------------------
     * Attacch current section to body_class. I think it's a bit mad like that 
     * WordPress doesn't have an action for body_id.
     *
     * @param   array       $classes        Array of body classes.
     * @return  array       $classes        Array of body classes.
     */

    public function set_section_body_class($classes) {
        $section_class = array();

        $section_class[] = 'section-';
        $section_class[] = self::$section['slug'];
        $classes[] = implode('', $section_class);

        return $classes;
    }

    /**
     * Setup Section Menus
     * -------------------------------------------------------------------------
     * Save generated sections menu to site options. Sections aren't expected
     * to change on a frequent basis, so run one, save, and only call again is
     * sections have changed.
     */

    private function menu_setup() {
        $sections = get_option(self::$keys['sections']);
        $menus = array();

        foreach ($sections['section'] as $id => $slug) {
            $menus['primary'][$slug] = $this->generate_menu_item($id);
            
            foreach ($this->category_children($id) as $child) {
                $menus['secondary'][$slug][] = $this->generate_menu_item($child);
            }
        }

        update_option(self::$keys['menus'], $menus);
        return $menus;
    }

    /**
     * Generate HTML Category List Item
     * -------------------------------------------------------------------------
     * Generate the actual HTML for the menu item based on the passed category.
     * 
     * @param   object/int  $category       Category which needs a link.
     * @return  string  $ln                 Generated menu item.
     */

    function generate_menu_item($category) {
        $category = get_category($category);

        if (!$category) {
            return;
        }

        $li = array();

        $li[] = '<li%s role="menuitem">';

        $li[] = sprintf('<a title="%s" href="%s">%s</a>',
            $category->cat_name,
            esc_url(get_category_link($category->cat_ID)),
            $category->cat_name
        );

        $li[] = '</li>';

        return implode('', $li);
    }

    /*
     * Output Section Menu
     * -------------------------------------------------------------------------
     * @param   array   $args      Arguments for menu output (type and classes).
     */

    function sections_menu($menu_type = 'primary', $menu_classes = array()) {
        $section = self::$section;

        // Get menu from saved menu, and reduce secondary if called.
        $menu = get_option(self::$keys['menus'])[$menu_type];

        if ($menu_type === 'secondary') {
            $menu = $menu[$section['slug']];
        }

        if (!empty($menu)) {
            foreach ($menu as $key => $item) {
                $classes = $menu_classes;
                $class_attr = '';

                if ($menu_type === 'primary') {
                    /* Primary menu items have hover and current section classes.
                     * Uncurrent-section only show section colour on hover. */
                    $uncurrent = ($key !== $section['slug']) ? '-hover' : '';
                    $classes[] = sprintf('section-%s-background%s', $key, $uncurrent);
                }
                
                if (!empty($classes)) {
                    // Menu items are generated with '<li%s role=...'
                    $class_attr = ' class="%s"';
                }

                // Put it all together and output.
                printf($item, sprintf($class_attr, implode(' ', $classes)));
            }
        }
    }
}

?>