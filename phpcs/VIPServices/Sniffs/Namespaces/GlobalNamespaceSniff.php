<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
// phpcs:disable Generic.Commenting.DocComment.LongNotCapital

namespace VIPServices\Sniffs\Namespaces;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * Class GlobalNamespaceSniff
 *
 * This class implements the Sniff interface and is responsible for checking for WordPress classes used in namespaced contexts.
 */
class GlobalNamespaceSniff implements Sniff
{

    /**
     * The array to store the use statements.
     *
     * @var array $use_statements
     */
    private $use_statements = array();

    /**
     * This variable holds an array of flagged errors.
     *
     * @var array
     */
    private $flagged_errors = array();

    /**
     * Registers the GlobalNamespaceSniff.
     *
     * This method is responsible for registering the GlobalNamespaceSniff with the PHP_CodeSniffer.
     * It is called when the sniff is being added to the PHP_CodeSniffer's sniffer pool.
     *
     * @return array
     */
    public function register(): array
    {
        return array( T_NAMESPACE, T_USE, T_FUNCTION, T_STRING );
    }

    /**
     * Process the file and perform checks for global namespaces.
     *
     * @param File $phpcs_file The file being processed.
     * @param int  $stack_ptr  The position of the current token in the stack.
     */
    public function process( File $phpcs_file, $stack_ptr )  // phpcs:ignore VIPServices.Functions.TypeDeclaration.MissingReturnType, VIPServices.Functions.TypeHinting.MissingParameterType
    {
        if (! $this->file_has_namespace($phpcs_file) ) {
            return;
        }

        $tokens = $phpcs_file->getTokens();

        switch ( $tokens[ $stack_ptr ]['code'] ) {
        case T_USE:
            $this->process_use_statement($phpcs_file, $stack_ptr);
            break;
        case T_FUNCTION:
            $this->process_function($phpcs_file, $stack_ptr);
            break;
        case T_STRING:
            $this->process_string($phpcs_file, $stack_ptr);
            break;
        }
    }

    /**
     * Checks if the file has a namespace.
     *
     * @param File $phpcs_file The PHP_CodeSniffer file being scanned.
     *
     * @return bool True if the file has a namespace, false otherwise.
     */
    private function file_has_namespace( File $phpcs_file ): bool
    {
        $tokens = $phpcs_file->getTokens();
        foreach ( $tokens as $token ) {
            if (T_NAMESPACE === $token['code'] ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Process the use statement.
     *
     * @param File $phpcs_file The PHP_CodeSniffer file being processed.
     * @param int  $stack_ptr  The stack pointer for the current token.
     *
     * @return void
     */
    private function process_use_statement( File $phpcs_file, int $stack_ptr ): void
    {
        $tokens        = $phpcs_file->getTokens();
        $end_ptr       = $phpcs_file->findNext(T_SEMICOLON, $stack_ptr);
        $use_statement = $phpcs_file->getTokensAsString($stack_ptr + 1, $end_ptr - $stack_ptr - 1);

        $this->use_statements[] = trim($use_statement);
    }

    /**
     * Process the function.
     *
     * @param File $phpcs_file The PHP_CodeSniffer file being processed.
     * @param int  $stack_ptr  The stack pointer for the current token.
     *
     * @return void
     */
    private function process_function( File $phpcs_file, int $stack_ptr ): void
    {
        $tokens           = $phpcs_file->getTokens();
        $function_end_ptr = $tokens[ $stack_ptr ]['parenthesis_closer'];
        $colon_ptr        = $phpcs_file->findNext(T_COLON, $function_end_ptr, $function_end_ptr + 2);

        $param_ptr = $phpcs_file->findNext(T_VARIABLE, $stack_ptr, $function_end_ptr);
        while ( false !== $param_ptr && $param_ptr < $function_end_ptr ) {
            $type_hint_ptr = $phpcs_file->findPrevious(array( T_STRING, T_NS_SEPARATOR ), $param_ptr - 1, $stack_ptr);
            if (false !== $type_hint_ptr && T_STRING === $tokens[ $type_hint_ptr ]['code'] ) {
                $type_hint   = '';
                $current_ptr = $type_hint_ptr;
                while ( T_STRING === $tokens[ $current_ptr ]['code'] || T_NS_SEPARATOR === $tokens[ $current_ptr ]['code'] ) {
                    $type_hint = $tokens[ $current_ptr ]['content'] . $type_hint;
                    --$current_ptr;
                }
                if (preg_match('/^WP_/', $type_hint) && ! $this->isuse_statement($type_hint) && ! $this->is_already_prefixed($type_hint_ptr, $tokens) ) {
                    $this->addUniqueError(
                        $phpcs_file,
                        "WordPress class '{$type_hint}' used as type hinting should be referenced with a leading backslash or imported with a 'use' statement.",
                        $param_ptr,
                        'GlobalNamespacetype_hint'
                    );
                }
            }
            $param_ptr = $phpcs_file->findNext(T_VARIABLE, $param_ptr + 1, $function_end_ptr);
        }

        if (false !== $colon_ptr ) {
            $return_type_ptr = $phpcs_file->findNext(array( T_STRING, T_NS_SEPARATOR ), $colon_ptr + 1, null, false, null, true);
            $return_type     = '';

            while ( T_STRING === $tokens[ $return_type_ptr ]['code'] || T_NS_SEPARATOR === $tokens[ $return_type_ptr ]['code'] ) {
                $return_type .= $tokens[ $return_type_ptr ]['content'];
                ++$return_type_ptr;
            }

            $return_types = explode('|', $return_type);
            foreach ( $return_types as $type ) {
                if (preg_match('/^WP_/', $type) && ! $this->isuse_statement($type) && ! $this->is_already_prefixed($colon_ptr + 1, $tokens) ) {
                    $this->addUniqueError(
                        $phpcs_file,
                        "WordPress class '{$type}' used as return type should be referenced with a leading backslash or imported with a 'use' statement.",
                        $colon_ptr,
                        'GlobalNamespacereturn_type'
                    );
                }
            }
        }
    }

    /**
     * Process the string.
     *
     * This method is responsible for processing the string in the given file at the specified stack pointer.
     *
     * @param File $phpcs_file The PHP_CodeSniffer file being processed.
     * @param int  $stack_ptr  The stack pointer indicating the position of the string in the file.
     *
     * @return void
     */
    private function process_string( File $phpcs_file, int $stack_ptr ): void
    {
        $tokens  = $phpcs_file->getTokens();
        $content = $tokens[ $stack_ptr ]['content'];

        if (preg_match('/^WP_/', $content) && ! $this->isuse_statement($content) ) {
            $prev_ptr = $phpcs_file->findPrevious(T_WHITESPACE, $stack_ptr - 1, null, true);
            if (T_NS_SEPARATOR !== $tokens[ $prev_ptr ]['code'] ) {
                // Check if the error has already been flagged for this context.
                if (! $this->is_error_flagged($phpcs_file, $stack_ptr, 'GlobalNamespace') ) {
                    $this->addUniqueError(
                        $phpcs_file,
                        "WordPress class '{$content}' should be referenced with a leading backslash or imported with a 'use' statement.",
                        $stack_ptr,
                        'GlobalNamespace'
                    );
                }
            }
        }
    }

    /**
     * Checks if a given class is an "use" statement.
     *
     * @param string $check_class The class to check.
     *
     * @return bool Returns true if the class is an "use" statement, false otherwise.
     */
    private function isuse_statement( string $check_class ): bool
    {
        foreach ( $this->use_statements as $use_statement ) {
            if (preg_match("/\b" . preg_quote($check_class, '/') . "\b/", $use_statement) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if the given function is already prefixed with a namespace.
     *
     * @param int   $ptr    The pointer to the function.
     * @param array $tokens The array of tokens.
     *
     * @return bool Returns true if the function is already prefixed with a namespace, false otherwise.
     */
    private function is_already_prefixed( int $ptr, array $tokens ): bool
    {
        return isset($tokens[ $ptr - 1 ]) && T_NS_SEPARATOR === $tokens[ $ptr - 1 ]['code'];
    }

    /**
     * Checks if an error flag is flagged.
     *
     * @param File   $phpcs_file The PHP_CodeSniffer file being scanned.
     * @param int    $ptr        The position of the token in the file.
     * @param string $code       The error code to check.
     *
     * @return bool True if the error flag is flagged, false otherwise.
     */
    private function is_error_flagged( File $phpcs_file, int $ptr, string $code ): bool
    {
        $line = $phpcs_file->getTokens()[ $ptr ]['line'];
        return isset($this->flagged_errors[ $line ][ $code ]);
    }

    /**
     * Adds a unique error to the given PHP_CodeSniffer_File object.
     *
     * @param File   $phpcs_file The PHP_CodeSniffer_File object to add the error to.
     * @param string $error      The error message.
     * @param int    $ptr        The position of the error in the file.
     * @param string $code       The error code.
     *
     * @return void
     */
    private function addUniqueError( File $phpcs_file, string $error, int $ptr, string $code ): void
    {
        $line = $phpcs_file->getTokens()[ $ptr ]['line'];

        if (! isset($this->flagged_errors[ $line ][ $code ]) ) {
            $phpcs_file->addError($error, $ptr, $code);
            $this->flagged_errors[ $line ][ $code ] = true;
        }
    }
}
