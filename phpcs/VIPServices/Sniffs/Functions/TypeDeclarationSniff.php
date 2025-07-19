<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
// phpcs:disable Generic.Commenting.DocComment.LongNotCapital

namespace VIPServices\Sniffs\Functions;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;
use WordPressCS\WordPress\Helpers\RulesetPropertyHelper;

/**
 * Class TypeDeclarationSniff
 *
 * This class implements the Sniff interface and is responsible for checking type declarations in functions.
 */
class TypeDeclarationSniff implements Sniff
{

    /**
     * The bash one-liner `find . -type f -name "*.php" -exec grep -Eho "class\s+WP_[a-zA-Z0-9_]+" {} \; | sed 's/class\s\+//' | sort -u`
     * was used to generate the list of known WordPress classes from WordPress 6.3.5.
     *
     * @var array
     */
    private $known_wordpress_classes = array(
    'WP_Admin_Bar',
    'WP_Ajax_Response',
    'WP_Ajax_Upgrader_Skin',
    'WP_Application_Passwords',
    'WP_Application_Passwords_List_Table',
    'WP_Automatic_Updater',
    'WP_Block',
    'WP_Block_Editor_Context',
    'WP_Block_List',
    'WP_Block_Parser',
    'WP_Block_Parser_Block',
    'WP_Block_Parser_Frame',
    'WP_Block_Pattern_Categories_Registry',
    'WP_Block_Patterns_Registry',
    'WP_Block_Styles_Registry',
    'WP_Block_Supports',
    'WP_Block_Template',
    'WP_Block_Type',
    'WP_Block_Type_Registry',
    'WP_Classic_To_Block_Menu_Converter',
    'WP_CLI_Logger',
    'WP_Comment',
    'WP_Comment_Query',
    'WP_Comments_List_Table',
    'WP_Community_Events',
    'WP_Customize_Background_Image_Control',
    'WP_Customize_Background_Image_Setting',
    'WP_Customize_Background_Position_Control',
    'WP_Customize_Code_Editor_Control',
    'WP_Customize_Color_Control',
    'WP_Customize_Control',
    'WP_Customize_Cropped_Image_Control',
    'WP_Customize_Custom_CSS_Setting',
    'WP_Customize_Date_Time_Control',
    'WP_Customize_Filter_Setting',
    'WP_Customize_Header_Image_Control',
    'WP_Customize_Header_Image_Setting',
    'WP_Customize_Image_Control',
    'WP_Customize_Manager',
    'WP_Customize_Media_Control',
    'WP_Customize_Nav_Menu_Auto_Add_Control',
    'WP_Customize_Nav_Menu_Control',
    'WP_Customize_Nav_Menu_Item_Control',
    'WP_Customize_Nav_Menu_Item_Setting',
    'WP_Customize_Nav_Menu_Location_Control',
    'WP_Customize_Nav_Menu_Locations_Control',
    'WP_Customize_Nav_Menu_Name_Control',
    'WP_Customize_Nav_Menus',
    'WP_Customize_Nav_Menu_Section',
    'WP_Customize_Nav_Menu_Setting',
    'WP_Customize_Nav_Menus_Panel',
    'WP_Customize_New_Menu_Control',
    'WP_Customize_New_Menu_Section',
    'WP_Customize_Panel',
    'WP_Customize_Partial',
    'WP_Customize_Section',
    'WP_Customize_Selective_Refresh',
    'WP_Customize_Setting',
    'WP_Customize_Sidebar_Section',
    'WP_Customize_Site_Icon_Control',
    'WP_Customize_Theme_Control',
    'WP_Customize_Themes_Panel',
    'WP_Customize_Themes_Section',
    'WP_Customize_Upload_Control',
    'WP_Customize_Widgets',
    'WP_Dashboard_Odyssey_Widget',
    'WP_Date_Query',
    'WP_Debug_Data',
    'WP_Dependencies',
    'WP_Duotone',
    'WP_Embed',
    'WP_Error',
    'WP_Fatal_Error_Handler',
    'WP_Feed_Cache',
    'WP_Feed_Cache_Transient',
    'WP_Filesystem_Base',
    'WP_Filesystem_Direct',
    'WP_Filesystem_FTPext',
    'WP_Filesystem_ftpsockets',
    'WP_Filesystem_SSH2',
    'WP_Filesystem_VIP',
    'WP_Filesystem_VIP_Uploads',
    'WP_Hook',
    'WP_HTML_Attribute_Token',
    'WP_HTML_Span',
    'WP_HTML_Tag_Processor',
    'WP_HTML_Text_Replacement',
    'WP_Http',
    'WP_Http_Cookie',
    'WP_Http_Curl',
    'WP_Http_Encoding',
    'WP_HTTP_Fsockopen',
    'WP_HTTP_IXR_Client',
    'WP_HTTP_Proxy',
    'WP_HTTP_Requests_Hooks',
    'WP_HTTP_Requests_Response',
    'WP_HTTP_Response',
    'WP_Http_Streams',
    'WP_Image_Editor',
    'WP_Image_Editor_GD',
    'WP_Image_Editor_Imagick',
    'WP_Import',
    'WP_Importer',
    'WP_Internal_Pointers',
    'WP_Links_List_Table',
    'WP_List_Table',
    'WP_List_Util',
    'WP_Locale',
    'WP_Locale_Switcher',
    'WP_MatchesMapRegex',
    'WP_Media_List_Table',
    'WP_Metadata_Lazyloader',
    'WP_Meta_Query',
    'WP_MS_Sites_List_Table',
    'WP_MS_Themes_List_Table',
    'WP_MS_Users_List_Table',
    'WP_Navigation_Fallback',
    'WP_Nav_Menu_Widget',
    'WP_Network',
    'WP_Network_Query',
    'WP_Object_Cache',
    'WP_oEmbed',
    'WP_oEmbed_Controller',
    'WP_Paused_Extensions_Storage',
    'WP_Plugin_Install_List_Table',
    'WP_Plugins_List_Table',
    'WP_Post',
    'WP_Post_Comments_List_Table',
    'WP_Posts_List_Table',
    'WP_Post_Type',
    'WP_Privacy_Data_Export_Requests_List_Table',
    'WP_Privacy_Data_Export_Requests_Table',
    'WP_Privacy_Data_Removal_Requests_List_Table',
    'WP_Privacy_Data_Removal_Requests_Table',
    'WP_Privacy_Policy_Content',
    'WP_Privacy_Requests_Table',
    'WP_Query',
    'WP_Recovery_Mode',
    'WP_Recovery_Mode_Cookie_Service',
    'WP_Recovery_Mode_Email_Service',
    'WP_Recovery_Mode_Key_Service',
    'WP_Recovery_Mode_Link_Service',
    'WP_Redirect',
    'WP_REST_Application_Passwords_Controller',
    'WP_REST_Attachments_Controller',
    'WP_REST_Autosaves_Controller',
    'WP_REST_Block_Directory_Controller',
    'WP_REST_Block_Pattern_Categories_Controller',
    'WP_REST_Block_Patterns_Controller',
    'WP_REST_Block_Renderer_Controller',
    'WP_REST_Blocks_Controller',
    'WP_REST_Block_Types_Controller',
    'WP_REST_Comment_Meta_Fields',
    'WP_REST_Comments_Controller',
    'WP_REST_Controller',
    'WP_REST_Edit_Site_Export_Controller',
    'WP_REST_Global_Styles_Controller',
    'WP_REST_Global_Styles_Revisions_Controller',
    'WP_REST_Menu_Items_Controller',
    'WP_REST_Menu_Locations_Controller',
    'WP_REST_Menus_Controller',
    'WP_REST_Meta_Fields',
    'WP_REST_Navigation_Fallback_Controller',
    'WP_REST_Pattern_Directory_Controller',
    'WP_REST_Plugins_Controller',
    'WP_REST_Post_Format_Search_Handler',
    'WP_REST_Post_Meta_Fields',
    'WP_REST_Posts_Controller',
    'WP_REST_Post_Search_Handler',
    'WP_REST_Post_Statuses_Controller',
    'WP_REST_Post_Types_Controller',
    'WP_REST_Request',
    'WP_REST_Response',
    'WP_REST_Revisions_Controller',
    'WP_REST_Search_Controller',
    'WP_REST_Search_Handler',
    'WP_REST_Server',
    'WP_REST_Settings_Controller',
    'WP_REST_Sidebars_Controller',
    'WP_REST_Site_Health_Controller',
    'WP_REST_Taxonomies_Controller',
    'WP_REST_Templates_Controller',
    'WP_REST_Term_Meta_Fields',
    'WP_REST_Terms_Controller',
    'WP_REST_Term_Search_Handler',
    'WP_REST_Themes_Controller',
    'WP_REST_URL_Details_Controller',
    'WP_REST_User_Meta_Fields',
    'WP_REST_Users_Controller',
    'WP_REST_Widgets_Controller',
    'WP_REST_Widget_Types_Controller',
    'WP_Rewrite',
    'WP_Role',
    'WP_Roles',
    'WP_Screen',
    'WP_Scripts',
    'WP_Session_Tokens',
    'WP_Sidebar_Block_Editor_Control',
    'WP_SimplePie_File',
    'WP_SimplePie_Sanitize_KSES',
    'WP_Site',
    'WP_Site_Health',
    'WP_Site_Health_Auto_Updates',
    'WP_Site_Icon',
    'WP_Sitemaps',
    'WP_Sitemaps_Index',
    'WP_Sitemaps_Posts',
    'WP_Sitemaps_Provider',
    'WP_Sitemaps_Registry',
    'WP_Sitemaps_Renderer',
    'WP_Sitemaps_Stylesheet',
    'WP_Sitemaps_Taxonomies',
    'WP_Sitemaps_Users',
    'WP_Site_Query',
    'WP_Style_Engine',
    'WP_Style_Engine_CSS_Declarations',
    'WP_Style_Engine_CSS_Rule',
    'WP_Style_Engine_CSS_Rules_Store',
    'WP_Style_Engine_Processor',
    'WP_Styles',
    'WP_Super_Cache',
    'WP_Taxonomy',
    'WP_Tax_Query',
    'WP_Term',
    'WP_Term_Query',
    'WP_Terms_List_Table',
    'WP_Text_Diff_Renderer_inline',
    'WP_Text_Diff_Renderer_Table',
    'WP_Textdomain_Registry',
    'WP_Theme',
    'WP_Theme_Install_List_Table',
    'WP_Theme_JSON',
    'WP_Theme_JSON_Data',
    'WP_Theme_JSON_Resolver',
    'WP_Theme_JSON_Schema',
    'WP_Themes_List_Table',
    'WP_Upgrader',
    'WP_Upgrader_Skin',
    'WP_User',
    'WP_User_Meta_Session_Tokens',
    'WP_User_Query',
    'WP_User_Request',
    'WP_User_Search',
    'WP_Users_List_Table',
    'WP_Version_Condition',
    'WP_Widget',
    'WP_Widget_Archives',
    'WP_Widget_Area_Customize_Control',
    'WP_Widget_Block',
    'WP_Widget_Calendar',
    'WP_Widget_Categories',
    'WP_Widget_Custom_HTML',
    'WP_Widget_Factory',
    'WP_Widget_Form_Customize_Control',
    'WP_Widget_Links',
    'WP_Widget_Media',
    'WP_Widget_Media_Audio',
    'WP_Widget_Media_Gallery',
    'WP_Widget_Media_Image',
    'WP_Widget_Media_Video',
    'WP_Widget_Meta',
    'WP_Widget_Pages',
    'WP_Widget_Recent_Comments',
    'WP_Widget_Recent_Posts',
    'WP_Widget_RSS',
    'WP_Widget_Search',
    'WP_Widget_Tag_Cloud',
    'WP_Widget_Text',
    );

    /**
     * This variable holds an array of custom classes.
     *
     * @var array
     */
    public $custom_classes = array();

    /**
     * Registers the function to be sniffed.
     *
     * @return array The list of token types to be sniffed.
     */
    public function register(): array
    {
        return array( T_FUNCTION );
    }

    /**
     * Process the file and check for type declarations.
     *
     * @param \PHP_CodeSniffer\Files\File $phpcs_file The PHP_CodeSniffer file where the
     *                                                token was found.
     * @param int                         $stack_ptr  The position in the PHP_CodeSniffer
     *                                                file's token stack where the token
     *                                                was found.
     *
     * @return void|int Optionally returns a stack pointer. The sniff will not be
     *                  called again on the current file until the returned stack
     *                  pointer is reached. Return (count($tokens) + 1) to skip
     *                  the rest of the file.     
     */
    public function process( File $phpcs_file, $stack_ptr )  // phpcs:ignore VIPServices.Functions.TypeDeclaration.MissingReturnType, VIPServices.Functions.TypeHinting.MissingParameterType
    {
        // Merge custom classes with known WordPress classes.
        $this->custom_classes = RulesetPropertyHelper::merge_custom_array($this->custom_classes, $this->known_wordpress_classes, false);

        $tokens = $phpcs_file->getTokens();

        // Check for return type.
        $this->check_return_type($phpcs_file, $stack_ptr);
    }

    /**
     * Checks the return type of a function.
     *
     * @param File $phpcs_file The PHP_CodeSniffer file being scanned.
     * @param int  $stack_ptr  The position of the function in the stack.
     *
     * @return void
     */
    private function check_return_type( File $phpcs_file, int $stack_ptr ): void
    {
        $tokens = $phpcs_file->getTokens();
        // Correctly identify the function's closing parenthesis to start searching for the return type.
        $function_declaration_end = $tokens[ $stack_ptr ]['parenthesis_closer'];

        // Get the name of the function.
        $function_name = $phpcs_file->getDeclarationName($stack_ptr);

        // List of magic methods that do not have a return type.
        $magic_methods_no_return_type = array(
        '__construct',
        '__destruct',
        '__clone',
        );

        // Check if the function is a magic method that should not have a return type.
        if (in_array(strtolower($function_name), $magic_methods_no_return_type, true) ) {
            // If it is, return early without adding an error.
            return;
        }

        // Find the colon that precedes the return type declaration.
        $colon_ptr = $phpcs_file->findNext(T_COLON, $function_declaration_end, $function_declaration_end + 3);

        if (false === $colon_ptr ) {
            // No colon found, hence no return type declared.
            $phpcs_file->addError("Missing return type declaration for function '$function_name'", $stack_ptr, 'MissingReturnType');
            return;
        }

        // Assuming the return type is immediately after the colon, find the end of the return type.
        $return_type_ptr     = $colon_ptr + 1;
        $return_type_end     = $phpcs_file->findNext(array( T_SEMICOLON, T_OPEN_CURLY_BRACKET ), $return_type_ptr) - 1;
        $return_type_content = trim($phpcs_file->getTokensAsString($return_type_ptr, ( $return_type_end - $return_type_ptr + 1 )));

        if (! $this->is_valid_return_type($return_type_content) ) {
            $phpcs_file->addError("Invalid return type declaration for function '$function_name'", $stack_ptr, 'InvalidReturnType');
        }
    }

    /**
     * Checks if the given return type is valid.
     *
     * @param  string $return_type The return type to validate.
     * @return bool Returns true if the return type is valid, false otherwise.
     */
    private function is_valid_return_type( string $return_type ): bool
    {
        /**
         * As of PHP 8, the language includes several enhancements and new features related to type declarations. Below is a comprehensive overview of return types you might encounter, including those introduced or becoming more relevant in PHP 8 and beyond. If you're developing a sniff for PHP_CodeSniffer to check for return type declarations, here are the types you should be aware of:
         * Scalar Types
         *
         *     bool: A boolean value (true or false).
         *     int: An integer number.
         *     float: A floating-point number.
         *     string: A string of characters.
         *
         * Compound Types
         *
         *     array: An array of values.
         *     object: An instance of any class.
         *     callable: A callable type, such as a function or a method.
         *     iterable: Either an array or an object implementing the Traversable interface, usable in foreach.
         *
         * Special Types
         *
         *     void: Indicates that the function does not return a value. Introduced in PHP 7.1.
         *     mixed: Indicates that the function can return any type of PHP value. Introduced in PHP 8.0.
         *     never: Indicates that the function will not return a value because it will either throw an exception or terminate script execution. Introduced in PHP 8.1.
         *
         * Pseudo-Types for Return Type Context (PHP 8+)
         *
         *     self: The same class in which the method is defined.
         *     parent: The parent class of the class in which the method is defined.
         *     static: The "late static binding" class name.
         *
         * Union Types (PHP 8+)
         *
         *     Union types allow a function to return one of multiple possible types, e.g., int|float.
         *
         * Nullable Types
         *
         *     Prefixing any type with a question mark (?), e.g., ?string, makes it nullable, allowing null as a possible return value.
         *
         * False Pseudo-Type in Union Types
         *
         *     In union types, false is treated somewhat as a pseudo-type, allowing functions to specify a return type that can include false, e.g., string|false. This is commonly used for functions that may return a specific type on success or false on failure.
         */
        $valid_scalar_types = array(
        'bool',
        'int',
        'float',
        'string',
        );

        $valid_compound_types = array(
        'array',
        'callable',
        'iterable',
        'object',
        );

        $additional_valid_types = array(
        'void',
        'mixed',
        'never',
        'null',
        'self',
        'parent',
        'static',
        'false', // Including 'false' for union types.
        );

        $valid_types = array_merge($valid_scalar_types, $valid_compound_types, $additional_valid_types);

        // Process union and nullable types.
        $types = explode('|', str_replace('?', '', $return_type)); // Strip nullable symbol for simplification.

        foreach ( $types as $type ) {
            $type = ltrim($type, '\\'); // Remove leading backslashes.
            if (! in_array($type, $valid_types, true) ) {
                // Check against known WordPress and custom classes.
                if (! in_array($type, $this->known_wordpress_classes, true) && ! in_array($type, $this->custom_classes, true) ) {
                    return false; // Invalid type.
                }
            }
        }

        return true; // All types are valid.
    }
}
