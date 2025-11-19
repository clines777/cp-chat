<?php

namespace App\Lib\Facade;

use App\Lib\Helper;
use App\Lib\ValidateResult;
use Hyperf\Context\ApplicationContext;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;

class Validator
{

    public static function validate(mixed $data, array $rules, array $messages = []): ValidateResult
    {
        try {
            $factory   = ApplicationContext::getContainer()->get(ValidatorFactoryInterface::class);
            $validator = $factory->make((array)$data, $rules, $messages);
        } catch (\Throwable $e) {
            $errMsg = Helper::getExpDetails($e);
            Log::error($errMsg, 'validate_err');

            return ValidateResult::make(false, [$e->getMessage()], []);
        }

        if ( ! $validator || $validator->fails()) {
            $errKeyValue = $validator->errors()->getMessages();

            return ValidateResult::make(false, $errKeyValue, []);
        }

        return ValidateResult::make(true, [], $validator->validated());
    }

}