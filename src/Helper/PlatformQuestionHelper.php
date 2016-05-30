<?php
/**
 * @file
 * Overrides Symfony's QuestionHelper to provide --yes and --no options.
 */

namespace Platformsh\Cli\Helper;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class PlatformQuestionHelper extends QuestionHelper
{

    /**
     * Ask the user to confirm an action.
     *
     * @param string          $questionText
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @param bool            $default
     *
     * @return bool
     */
    public function confirm($questionText, InputInterface $input, OutputInterface $output, $default = true)
    {
        $questionText .= ' <question>' . ($default ? '[Y/n]' : '[y/N]') . '</question> ';

        $yes = $input->hasOption('yes') && $input->getOption('yes');
        $no = $input->hasOption('no') && $input->getOption('no');
        if ($yes && !$no) {
            $output->writeln($questionText . 'y');
            return true;
        } elseif ($no && !$yes) {
            $output->writeln($questionText . 'n');
            return false;
        }
        $question = new ConfirmationQuestion($questionText, $default);

        return $this->ask($input, $output, $question);
    }

    /**
     * @param array           $items   An associative array of choices.
     * @param string          $text    Some text to precede the choices.
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @param mixed           $default A default (as a key in $items).
     *
     * @throws \RuntimeException on failure
     *
     * @return mixed
     *   The chosen item (as a key in $items).
     */
    public function choose(array $items, $text = 'Enter a number to choose an item:', InputInterface $input, OutputInterface $output, $default = null)
    {
        if (count($items) === 1) {
            return key($items);
        }
        $itemList = array_values($items);
        $defaultKey = $default !== null ? array_search($default, $itemList) : null;
        $question = new ChoiceQuestion($text, $itemList, $defaultKey);
        $question->setMaxAttempts(5);

        // Unfortunately the default autocompletion can cause '2' to be
        // completed to '20', etc.
        $question->setAutocompleterValues(null);

        $choice = $this->ask($input, $output, $question);
        $choiceKey = array_search($choice, $items);
        if ($choiceKey === false) {
            throw new \RuntimeException("Invalid value: $choice");
        }

        return $choiceKey;
    }

    /**
     * Ask a simple question which requires input.
     *
     * @param string          $questionText
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @param mixed           $default
     *
     * @return string
     *   The user's answer.
     */
    public function askInput($questionText, InputInterface $input, OutputInterface $output, $default = null)
    {
        if ($default !== null) {
            $questionText .= ' <question>[' . $default . ']</question>';
        }
        $questionText .= ': ';
        $question = new Question($questionText, $default);

        return $this->ask($input, $output, $question);
    }
}