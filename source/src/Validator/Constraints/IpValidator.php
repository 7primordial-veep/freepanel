<?php

namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
class IpValidator extends ConstraintValidator
{
    
    public function validate($value, Constraint $constraint) : void
    {
        if (null === $value || '' === $value) {
            return;
        }
        if (!is_scalar($value) && !(is_object($value) && method_exists($value, '__toString'))) {
            throw new UnexpectedTypeException($value, 'string');
        }
        $ipAddress = (string) $value;
        $ipParts = explode('/', $ipAddress);
        $ip = $ipParts[0] ?? '';
        $netmask = $ipParts[1] ?? '';
        $isIpv6 = substr_count($ipAddress, ':') ? true : false;
        $isValidIp = false;
        if (true === $isIpv6) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $isValidIp = true;
            }
        } else {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $isValidIp = true;
            }
        }
        if (true === $isValidIp && false === empty($netmask)) {
            $netmask = (int) $netmask;
            $isNetmaskValid = false;
            $max = true === $isIpv6 ? 128 : 32;
            $isNetmaskValid = $netmask >= 0 && $netmask <= $max;
            if (false === $isNetmaskValid) {
                $isValidIp = false;
            }
        }
        if (false === $isValidIp) {
            $this->context->addViolation($constraint->message);
        }
    }
}