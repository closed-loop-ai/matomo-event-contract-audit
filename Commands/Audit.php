<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace Piwik\Plugins\EventContractAudit\Commands;

use InvalidArgumentException;
use JsonException;
use Piwik\NoAccessException;
use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugins\EventContractAudit\ComparisonService;
use Symfony\Component\Console\Output\OutputInterface;

class Audit extends ConsoleCommand
{
    protected function configure()
    {
        $this->setName('event-contract:audit');
        $this->setDescription('Compare a local expected event-pair plan with one Matomo Events report.');
        $this->addRequiredValueOption('idsite', null, 'One positive Matomo site ID.');
        $this->addRequiredValueOption('period', null, 'Native report period.');
        $this->addRequiredValueOption('date', null, 'Explicit native report date.');
        $this->addRequiredValueOption('plan', null, 'Local JSON plan file.');
        $this->addOptionalValueOption('format', null, 'Output format; only json is supported.', 'json');
    }

    protected function doExecute(): int
    {
        try {
            $input = $this->getInput();
            foreach (['idsite', 'period', 'date', 'plan'] as $option) {
                if (!is_string($input->getOption($option)) || $input->getOption($option) === '') {
                    throw new InvalidArgumentException('Provide idsite, period, date and a local plan file.');
                }
            }
            if ($input->getOption('format') !== 'json') {
                throw new InvalidArgumentException('Only JSON output is supported.');
            }
            $path = $input->getOption('plan');
            if (strpos($path, '://') !== false || !is_file($path) || !is_readable($path)) {
                throw new InvalidArgumentException('The local plan file is not readable.');
            }
            $raw = file_get_contents($path);
            if ($raw === false) {
                throw new InvalidArgumentException('The local plan file could not be read.');
            }
            $object = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
            if (!$object instanceof \stdClass || !isset($object->pairs) || !is_array($object->pairs)) {
                throw new InvalidArgumentException('Plan must be a JSON object with a pairs array.');
            }
            foreach ($object->pairs as $pair) {
                if (!$pair instanceof \stdClass) {
                    throw new InvalidArgumentException('Each pair must be a JSON object.');
                }
            }
            $plan = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $result = (new ComparisonService())->compare($input->getOption('idsite'),
                $input->getOption('period'), $input->getOption('date'), $plan);
            $this->getOutput()->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);
            return 0;
        } catch (InvalidArgumentException | JsonException $error) {
            $this->getOutput()->writeln('Invalid input: ' . $error->getMessage(), OutputInterface::OUTPUT_RAW);
            return 2;
        } catch (NoAccessException $error) {
            $this->getOutput()->writeln('The selected site is not accessible.', OutputInterface::OUTPUT_RAW);
            return 1;
        } catch (\Exception $error) {
            $this->getOutput()->writeln('The selected native report could not be compared.', OutputInterface::OUTPUT_RAW);
            return 1;
        }
    }
}
