<?php 

/**
* Site Sections
* -----------------------------------------------------------------------------
 * @category   PHP Script
 * @package    Section Manager
 * @author     Mark Grealish <mark@bhalash.com>
 * @copyright  Copyright (c) 2014-2015, Mark Grealish
 * @license    https://www.gnu.org/copyleft/gpl.html The GNU GPL v3.0
 * @version    2.0
 * @link       https://github.com/bhalash/section-manager
 *
 * This file is part of Section Manager.
 * 
 * Section Manager is free software: you can redistribute it and/or modify it 
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version.
 * 
 * Section Manager is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more
 * details.
 * 
 * You should have received a copy of the GNU General Public License along with 
 * Section Manager. If not, see <http://www.gnu.org/licenses/>.
 */ 

if (!defined('ABSPATH')) {
    die('-1');
}

interface SM_Interface {
    // Get the sibling section categories of a category.
    public function section_child_categories($category);
    // Return the slug of the section to which a category belong.
    public function get_category_section_slug($category);
    // Return the ID of the section to which a category belong.
    public function get_category_section_id($category);
    // Required to be public in order to determine section.
    public function evaluate_current_section();
    // Required to be public in order to set class.
    public function set_section_body_class($classes);
}

class Section_Manager implements SM_Interface {
    private static $errors = array(
        'instance' => 'Error: Section Manager can only be instantiated once.',
        'imaginary' => '%s does not exist in the list of category sections and will be ignored as a default.',
        'empty_section' => 'Error: An array of categories must be passed to Section Manager.',
        'not_category' => '"%s" is not a valid category and will be skipped',
        'is_child' => '"%s" is a child category and will be skipped'
    );

    private static $opts = array(
        'sections' => 'sm_sections_list',
        'default' => 'sm_default_section',
        'current' => 'sm_current_section'
    );

    static $instantiated;

    public static $current_section;
    public static $default_section;
    public static $sections_list;
    private static $class_prefix;

    public function __construct($categories, $default_section = null, $prefix = null) {
        // Test instantiation.

        if (self::$instantiated) {
            // More than one running instance can lead to strange things.
            throw new Exception(self::$errors['instance']);
        }
        
        self::$instantiated = true;

        // Parse class args.

        if (!is_array($categories) || empty($categories)) {
            // Throw error if no sections were provided.
            throw new Exception(self::$errors['empty_section']);
        }

        if (!$default_section) {
            // First passed section becomes default.
            $default_section = $categories[0];
        }

        if (!$prefix) {
            // All output slugs and class names are prepended with this.
            $prefix = 'section--';
        }

        // Setup all sections.

        self::$sections_list = get_option(self::$opts['sections']);
        self::$class_prefix = $prefix;

        if (!self::$sections_list || $categories !== self::$sections_list) {
            // Update generated options and menu if sections have changed.
            self::$sections_list = $this->setup_sections($categories);
            // $this->menu_setup();
        }

        // Establish default section.
        // Default section is presented when a section cannot be established.

        self::$default_section = get_option(self::$opts['default']);

        if (!self::$default_section || self::$default_section !== $default_section) {
            self::$default_section = $this->setup_default_section($default_section);
        }

        // Establish current section.

        add_action('wp_head', array($this, 'evaluate_current_section'));

        // Append current section class to body tag.
        
        add_filter('body_class', array($this, 'set_section_body_class'));
    }

    /**
     * Validate Site Section Categories
     * -------------------------------------------------------------------------
     * Site section categories are passed through an initial array. This 
     * validates the array and sets up the class sections variable.
     * 
     * @param   array       $categories         Structured array of categories.
     * @return  array       $sections           Parsed array of sections.
     */

    private function setup_sections($categories) {
        $sections = array();

        foreach ($categories as $category) {
            if (!($category = get_category($category))) {
                // 1. Passed category IDs must represent categories.
                trigger_error(sprintf(self::$errors['not_category'], $category), E_USER_WARNING);
                continue;
            }

            if ($category->category_parent) {
                // 2. Sections derive from categories.
                trigger_error(sprintf(self::$errors['is_child'], $category), E_USER_WARNING);
                continue;
            }

            if (in_array($category->cat_ID, $sections)) {
                // 3. If a duplicate section is passed.
                trigger_error(sprintf(self::$errors['duplicate'], $category), E_USER_WARNING);
                continue;
            }

            $sections[$category->slug] = $category->cat_ID;
        }

        update_option(self::$opts['sections'], $sections, true);
        return $sections;
    }

    /**
     * Set Default Section
     * -------------------------------------------------------------------------
     * @param   object/int      $section_id        Section category ID.
     * @return  object/int      $section_id        Section category ID.
     */

    private function setup_default_section($section) {
        if (!in_array($section, self::$sections_list)) {
            trigger_error(sprintf(self::$errors['imaginary'], $category), E_USER_WARNING);
            $section = self::$sections_list[reset(self::$sections_list)];
        }

        update_option(self::$opts['default'], $section);
        return $section;
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

    public function evaluate_current_section() {
        $current_id = 0;

        if (!is_404()) {
            if (is_home() || is_front_page() || is_page()) {
                // 1. If home page or main index, default to first item.
                $current_id = self::$default_section;
            } else if (is_category() || is_single()) {
                global $post;

                // 2. Else set categroy by the parent category.
                if (is_category()) {
                    $category = get_query_var('cat');
                } else if (is_single()) {
                    // 3. Simply grab the first post category and run with that.
                    $category = get_the_category()[0]->cat_ID;
                }

                $category = get_category($this->get_category_ancestor($category));

                if ($this->is_section_category($category)) {
                    $current_id = $category->cat_ID;
                }
            }
        }

        if (!$current_id) {
            $current_id = 999;
        }

        self::$current_section = $current_id;
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
        return !!in_array($category->cat_ID, self::$sections_list);
    }

    /**
     * Recursively Ascend to Category's Parent 
     * -------------------------------------------------------------------------
     * @param   int     $cat_id          Category ID
     * @return  int     $cat_id          ID of original category's parent.
     */

    private function get_category_ancestor($cat_id = null) {
        $category = get_category($cat_id);

        if ($category && $category->category_parent) {
            $cat_id = $this->get_category_ancestor($category->category_parent);
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

    public function section_child_categories($category) {
        $category = get_category($this->get_category_ancestor($category));
        $children = array();

        if (($category = get_categories(array('child_of' => $category->cat_ID)))) {
            foreach($category as $cat) {
                $children[] = $cat->cat_ID;
            }
        }

        return $children;
    }

    /**
     * Get Slug of Section Related to Input Category
     * -------------------------------------------------------------------------
     * @param   object/id       $category       Category ID or object.
     * @return  string          $slug           Slug for section.
     */

    public function get_category_section_slug($category) {
        $slug = '';

        $category = get_category($this->get_category_ancestor($category));

        if ($category && in_array($category->cat_ID, self::$sections_list)) {
            $slug = $category->slug;
        } else {
            $slug = 'other';
        }

        return $slug;
    }

    /**
     * Get ID of Section Related to Input Category
     * -------------------------------------------------------------------------
     * @param   object/id       $category       Category ID or object.
     * @return  string          $id             ID of section.
     */

    public function get_category_section_id($category) {
        $id = 0;

        $category = get_category($this->get_category_ancestor($category));

        if (array_search($category->cat_ID, self::$sections_list)) {
            $id = $category->cat_ID;
        } else {
            $id = 999;
        }

        return $id;
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
        $slug = $this->get_category_section_slug(self::$current_section);
        $classes[] = self::$class_prefix . $slug;
        return $classes;
    }
}

?>
