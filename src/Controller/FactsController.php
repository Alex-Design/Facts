<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class FactsController extends AbstractController
{
    /**
     * The main endpoint for gathering JSON data input
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function calculateFactsExpression(Request $request): JsonResponse
    {
        $bodyContent = $request->getContent();

        $decodedInformation = $this->validateJSONStructure($bodyContent);
        $finalData = $this->calculateGivenFacts(
            $decodedInformation['decoded_input'],
            $decodedInformation['nested']
        );
        $finalValue = $this->performFinalCalculation(
            $finalData['function'],
            $finalData['first_value_data']['final_value'],
            $finalData['second_value_data']['final_value']
        );

        return new JsonResponse(['value' => $finalValue]);
    }

    /**
     * Check that a given string is valid JSON
     * and has the correct structure, return error otherwise
     *
     * @param string $input
     * @return array
     */
    private function validateJSONStructure(string $input): array
    {
        $decodedInput = json_decode($input, true);
        if (json_last_error()) {
            throw new BadRequestHttpException('Please ensure that you have sent valid JSON.');
        }

        $topLevelProperties = ['expression', 'security'];
        $innerProperties = ['fn', 'a', 'b'];
        $nested = false;
        $validExampleNoNest = '{
          "expression": { "fn": "/", "a": "price", "b": "shares" },
          "security": "BCD" 
        }';
        $validExampleWithNest = '{
          "expression": {
            "fn": "-", 
            "a": {"fn": "-", "a": "eps", "b": "shares"}, 
            "b": {"fn": "-", "a": "assets", "b": "liabilities"}
          },
          "security": "CDE"
        }';

        $this->validateNestedJSON($topLevelProperties, $decodedInput, $validExampleNoNest);
        $this->validateNestedJSON($innerProperties, $decodedInput['expression'], $validExampleNoNest);

        // If this exists, this means that a and b should be nested calculations of their own
        if (is_array($decodedInput['expression']['a']) && array_key_exists('fn', $decodedInput['expression']['a'])) {
            $this->validateNestedJSON($innerProperties, $decodedInput['expression']['a'], $validExampleWithNest);
            $this->validateNestedJSON($innerProperties, $decodedInput['expression']['b'], $validExampleWithNest);
            $nested = true;
        }

        return ['decoded_input' => $decodedInput, 'nested' => $nested];
    }

    /**
     * Validate the nested JSON, throwing an exception if invalid
     *
     * @param array $properties
     * @param array $array
     * @param string $validExample
     * @return void
     */
    private function validateNestedJSON(array $properties, array $array, string $validExample): void
    {
        foreach ($properties as $property) {
            if (!array_key_exists($property, $array)) {
                throw new BadRequestHttpException('The property "' . $property . '" with a value is missing.
                 Valid example:
                  ' . $validExample);
            }
        }
    }

    /**
     * Perform the calculations for the given data
     *
     * @param array $data
     * @param bool $nested
     * @return array
     */
    private function calculateGivenFacts(array $data, bool $nested): array
    {
        // If it is not nested, it is a direct calculation on two values
        $securityId = $this->interpretSecurity($data['security']);

        if (!$nested) {
            $function = $data['expression']['fn'];
            $firstValueData = $this->interpretAttribute($data['expression']['a']);
            $secondValueData = $this->interpretAttribute($data['expression']['b']);

            // If the value given was a name, then we currently have the ID values - we now need the end integers for calculations
            if ($firstValueData['was_name'] === true) {
                $firstValueData['final_value'] = $this->getValueForAttribute($firstValueData['id_value'], $securityId);
            } else {
                $firstValueData['final_value'] = $secondValueData['id_value'];
            }

            if ($secondValueData['was_name'] === true) {
                $secondValueData['final_value'] = $this->getValueForAttribute($secondValueData['id_value'], $securityId);
            } else {
                $secondValueData['final_value'] = $secondValueData['value'];
            }

            return ['function' => $function, 'first_value_data' => $firstValueData, 'second_value_data' => $secondValueData];

            // Otherwise, the calculation needs to be done on each nested part first
        } else {
            $restructuredDataA = ['expression' => $data['expression']['a'], 'security' => $data['security']];
            $restructuredDataB = ['expression' => $data['expression']['b'], 'security' => $data['security']];

            $firstDataset = $this->calculateGivenFacts($restructuredDataA, false);
            $secondDataset = $this->calculateGivenFacts($restructuredDataB, false);

            $finalFirstValue = $this->performFinalCalculation(
                $firstDataset['function'],
                $firstDataset['first_value_data']['final_value'],
                $firstDataset['second_value_data']['final_value']
            );

            $finalSecondValue = $this->performFinalCalculation(
                $secondDataset['function'],
                $secondDataset['first_value_data']['final_value'],
                $secondDataset['second_value_data']['final_value']
            );

            return [
                'function' => $data['expression']['fn'],
                'first_value_data' => ['final_value' => $finalFirstValue],
                'second_value_data' => ['final_value' => $finalSecondValue]
            ];
        }
    }

    /**
     * @param string $security
     * @return int
     */
    private function interpretSecurity(string $security): int
    {
        $em = $this->getDoctrine()->getManager();
        $query = 'SELECT id FROM securities where symbol = :security';
        $statement = $em->getConnection()->prepare($query);
        $statement->bindValue('security', $security);
        $statement->execute();

        return $statement->fetchOne();
    }

    /**
     * @param string|integer $attribute
     * @return array
     */
    private function interpretAttribute($attribute): array
    {
        // If it's an integer, we are using it straight away for the calculation
        if (gettype($attribute) === 'integer') {
            return ['was_name' => false, 'value' => $attribute];
        }

        // Otherwise, find ID that corresponds to this string and return it
        $em = $this->getDoctrine()->getManager();
        $query = 'SELECT id FROM attributes where name = :name';
        $statement = $em->getConnection()->prepare($query);
        $statement->bindValue('name', $attribute);
        $statement->execute();
        $id = $statement->fetchOne();

        return ['was_name' => true, 'id_value' => $id];
    }

    /**
     * Get the value for an attribute from the facts table, based on the securityId
     *
     * @param int $attributeId
     * @param int $securityId
     * @return int
     */
    private function getValueForAttribute(int $attributeId, int $securityId): int
    {
        $em = $this->getDoctrine()->getManager();
        $query = 'SELECT value FROM facts where attribute_id = :attribute_id and security_id = :security_id';
        $statement = $em->getConnection()->prepare($query);
        $statement->bindValue('attribute_id', $attributeId);
        $statement->bindValue('security_id', $securityId);
        $statement->execute();

        return $statement->fetchOne();
    }

    /**
     * Perform the final calculation and return the value
     *
     * @param string $function
     * @param int $firstValue
     * @param int $secondValue
     * @return int
     */
    private function performFinalCalculation(string $function, int $firstValue, int $secondValue): int
    {
        switch ($function) {
            case '*':
                return $firstValue * $secondValue;
            case '-':
                return $firstValue - $secondValue;
            case '+':
                return $firstValue + $secondValue;
            case '/':
                return $firstValue / $secondValue;
            default:
                throw new \InvalidArgumentException('Function ' . $function . ' not implemented for calculation.');
        }
    }
}