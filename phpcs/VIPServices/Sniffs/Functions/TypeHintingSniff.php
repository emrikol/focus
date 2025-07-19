<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
// phpcs:disable Generic.Commenting.DocComment.LongNotCapital

namespace VIPServices\Sniffs\Functions;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * Class TypeHintingSniff
 *
 * This class implements the Sniff interface and is responsible for checking type hinting in functions.
 */
class TypeHintingSniff implements Sniff
{

    /**
     * Registers the TypeHintingSniff.
     *
     * This method is responsible for registering the TypeHintingSniff with the PHP_CodeSniffer.
     * It is called when the sniff is loaded and initializes the sniff's rules and settings.
     *
     * @return array
     */
    public function register(): array
    {
        return array( T_FUNCTION );
    }

    /**
     * Process the file and check for type hinting.
     *
     * @param File $phpcs_file The file being processed.
     * @param int  $stack_ptr  The position of the current token in the stack.
     */
    public function process( File $phpcs_file, $stack_ptr )  // phpcs:ignore VIPServices.Functions.TypeDeclaration.MissingReturnType, VIPServices.Functions.TypeHinting.MissingParameterType
    {
        $tokens = $phpcs_file->getTokens();

        // Check for parameter types.
        $this->check_parameter_types($phpcs_file, $stack_ptr);
    }

    /**
     * Check the parameter types.
     *
     * @param File $phpcs_file The PHP_CodeSniffer file being scanned.
     * @param int  $stack_ptr  The position of the current token in the stack.
     *
     * @return void
     */
    private function check_parameter_types( File $phpcs_file, int $stack_ptr ): void
    {
        $parameters = $phpcs_file->getMethodParameters($stack_ptr);

        foreach ( $parameters as $param ) {
            if ('' === $param['type_hint'] ) {
                $function_name = $phpcs_file->getDeclarationName($stack_ptr);
                $phpcs_file->addError("Missing type declaration for parameter '{$param['name']}' in function '$function_name'", $stack_ptr, 'MissingParameterType');
            }
        }
    }
}
