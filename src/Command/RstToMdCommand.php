<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

#[AsCommand(
    name: 'app:rst-to-md',
    description: 'Add a short description for your command',
)]
class RstToMdCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $inputDir  = __DIR__ . '/../../public/input';
        $outputDir = __DIR__ . '/../../public/output';

        $filesystem = new Filesystem();

        $this->emptyDirectory($filesystem, $outputDir);

        $this->scanAndGenerate($filesystem, $inputDir, $outputDir, $output);

        return Command::SUCCESS;
    }

    private function scanAndGenerate(Filesystem $filesystem, $inputDir, $outputDir, OutputInterface $output): void
    {
        $finder = new Finder();

        $finder->files()->in($inputDir);

        if ($finder->hasResults()) {
            foreach ($finder as $file) {
                $fileNameWithExtension = $file->getRelativePathname();

                $fileNameWithExtension = str_replace('.rst', '.md', $fileNameWithExtension);

                $fullFilePath = $outputDir . '/' . $fileNameWithExtension;

                $fileContent = $this->parseRst($file->getContents());

                $filesystem->appendToFile($fullFilePath, $fileContent);
            }
        }
    }

    private function emptyDirectory(Filesystem $filesystem, $dir): void
    {
        $filesystem->remove($dir);
        $filesystem->mkdir($dir);
    }

    private function parseRst(string $fileContent)
    {
        $fileLines = explode("\n", $fileContent);
        $lineCount = count($fileLines);

        $virtualFileContent = [
            'blocks' => [],
            'links'  => [],
        ];

        $blockIndex = 0;
        $linkIndex  = 0;
        for ($lineIndex = 0; $lineIndex < $lineCount; $lineIndex++) {
            $fileLine = $fileLines[$lineIndex];

            if (!array_key_exists($blockIndex, $virtualFileContent['blocks'])) {
                $virtualFileContent['blocks'][$blockIndex] = [
                    'type'  => null,
                    'value' => '',
                ];
            }

            switch (true) {
                # Empty Line
                case preg_match('/^$/', $fileLine):
                    $virtualFileContent['blocks'][$blockIndex]['type'] = 'lineBreak';
                    $blockIndex++;
                    break;
                # H1
                case preg_match('/^=*$/', $fileLine):
                    $virtualFileContent['blocks'][$blockIndex]['type'] = 'H1';
                    $blockIndex++;
                    break;
                # H2
                case preg_match('/^-*$/', $fileLine):
                    $virtualFileContent['blocks'][$blockIndex]['type'] = 'H2';
                    $blockIndex++;
                    break;
                # Code block
                case preg_match('/^\.{2} code-block:{2} (.*)$/', $fileLine, $matches):
                    $virtualFileContent['blocks'][$blockIndex]['type']     = 'codeBlock';
                    $virtualFileContent['blocks'][$blockIndex]['language'] = $matches[1];

                    $this->parseMultiLinesBlock($virtualFileContent, $blockIndex, $fileLines, $lineIndex);

                    break;
                # seealso block
                case preg_match('/^\.{2} seealso:{2}$/', $fileLine):
                    $virtualFileContent['blocks'][$blockIndex]['type'] = 'seeAlso';

                    $this->parseMultiLinesBlock($virtualFileContent, $blockIndex, $fileLines, $lineIndex);

                    break;
                # toctree block
                case preg_match('/^\.{2} toctree:{2}$/mi', $fileLine):
                    $virtualFileContent['blocks'][$blockIndex]['type'] = 'tocTree';

                    $this->parseMultiLinesBlock($virtualFileContent, $blockIndex, $fileLines, $lineIndex);

                    break;
                # Links
                case preg_match('/^\.{2} _`(.*)`: (.+)$/mi', $fileLine, $matches):
                    if (!array_key_exists($linkIndex, $virtualFileContent['links'])) {
                        $virtualFileContent['links'][$linkIndex] = [
                            'label' => '',
                            'value' => '',
                        ];
                    }

                    $virtualFileContent['links'][$linkIndex]['label'] = $matches[1];
                    $virtualFileContent['links'][$linkIndex]['value'] = $matches[2];
                    $linkIndex++;
                    break;
                default:
                    $virtualFileContent['blocks'][$blockIndex]['type']  = 'text';
                    $virtualFileContent['blocks'][$blockIndex]['value'] .= $fileLine . "\n";
                    if (isset($fileLines[$lineIndex + 1]) && preg_match('/^$/', $fileLines[$lineIndex + 1])) {
                        $blockIndex++;
                    }
                    break;
            }
        }

        return $this->generateFileContentFromBlock($virtualFileContent);
    }

    private function parseMultiLinesBlock(
        array &$virtualFileContent,
        int &$blockIndex,
        array $fileLines,
        int &$lineIndex
    ): void {
        $stop = false;

        do {
            if (isset($fileLines[$lineIndex + 1])) {
                $virtualFileContent['blocks'][$blockIndex]['value'] .= $fileLines[$lineIndex + 1] . "\n";
            } else {
                $stop = true;
            }

            if (isset($fileLines[$lineIndex + 1])) {
                if (preg_match('/^$/', $fileLines[$lineIndex + 1])) {
                    if (isset($fileLines[$lineIndex + 2])) {
                        if (!preg_match('/^(?: {4})+.*$/', $fileLines[$lineIndex + 2])) {
                            $stop = true;
                        }
                    }
                }
            }

            $lineIndex++;
        } while (!$stop);

        $blockIndex++;
    }

    private function generateFileContentFromBlock(array $virtualFileContent): string
    {
        $newFileContent = '';

        foreach ($virtualFileContent['blocks'] as $contentBlock) {
            $blockType    = $contentBlock['type'];
            $blockValue   = $contentBlock['value'];
            $blockContent = '';
            switch (true) {
                case $blockType === 'H1';
                    $blockContent = "---\ntitle: ${blockValue}---\n";
                    break;
                case $blockType === 'H2';
                    $blockContent = "## ${blockValue}";
                    break;
                case $blockType === 'lineBreak';
                    $blockContent = "\n";
                    break;
                case $blockType === 'text';
                    $blockValue   = preg_replace('/`{2}([\w()@\/\.]*)`{2}/mi', "`$1`", $blockValue);
                    $blockValue   = preg_replace('/^\* /mi', "- ", $blockValue);
                    $blockValue   = preg_replace('/\*{2}(\w+)\*{2}/mi', "*$1*", $blockValue);
                    $blockValue   = preg_replace('/^\.{2} _.+:$/mi', '', $blockValue);
                    $blockContent = "${blockValue}";
                    break;
                case $blockType === 'codeBlock';
                    $codeLanguage = $contentBlock['language'];
                    # Truncate last line break
                    $blockValue   = preg_replace('/^\n$/mi', '', $blockValue);
                    $blockValue   = preg_replace('/^( {4})/mi', '', $blockValue);
                    $blockContent = "``` ${codeLanguage}${blockValue}```\n\n";
                    break;
                case $blockType === 'seeAlso';
                    $blockValue   = preg_replace('/ {4}/mi', '', $blockValue);
                    $blockContent = "## See Also\n${blockValue}";
                    break;
                case $blockType === 'tocTree';
                    $blockValue = preg_replace('/^ {4}:maxdepth: 1$\n\n/mi', '', $blockValue);
                    preg_match_all('/^ {4}((.+)\/(.+))$/mi', $blockValue, $matches);
                    $blockValue   = preg_replace_callback('/^ {4}((.+)\/(.+))$/mi', function ($matches) {
                        $label = $matches[3];
                        $label = str_replace('-', ' ', $label);
                        $label = ucfirst($label);
                        $value = $matches[1];
                        return "- [${label}]($value)";
                    }, $blockValue);
                    $blockContent = "${blockValue}";
                    break;
            }
            $newFileContent .= $blockContent;
        }

        foreach ($virtualFileContent['links'] as $link) {
            $linkLabel = $link['label'];
            $linkValue = $link['value'];
            $newFileContent = preg_replace("/`${linkLabel}`_/mi", "[${linkLabel}](${linkValue})", $newFileContent);
        }

        $newFileContent = preg_replace('/^\n{2}$/mi', '', $newFileContent);

        return $newFileContent;
    }
}
