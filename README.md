# Social Meta
[section-manager.php](section-manager.php) is a self-contained library for a WordPress theme that segregates your site into sections based on input parent categories.

## Usage
Instantiate the class with: 

    $sections = new Section_Manager(array(
        'categories' => array(191, 154, 155, 156, 157, 159, 187, 158), 
        'home' => 191
    ));

### Categories
Only parent categories will be used -- that is, a category without a parent.

### Home
Home represents the default category -- what menu is shown on your home page, on search pages, etc.

### TODO
Add tag, search and calendar archive menu items.

### Menus 
Primary and secondary menus can be called with:

    global $sections;

    $sections->sections_menu(
        'primary/secondary',
        array('optional-classes')
    );

## Support
Your mileage will vary; while this library is suitable for my site, it's compatibility with yours is unknowable. Caveat emptor! Pull requests and forks are welcome. If you have a simple support question, email <mark@bhalash.com>.

## Copyright and License
All code is Copyright (c) 2015 [http://www.bhalash.com/](Mark Grealish). All of the code in the project is licensed under the GPL v3 or later, except as may be otherwise noted.

> This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public > License, version 3, as published by the Free Software Foundation.
> 
> This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
> 
> You should have received a copy of the GNU General Public License along with this program; if not, write to the Free Software Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA

A copy of the license is included in the root of the plugin’s directory. The file is named LICENSE.
