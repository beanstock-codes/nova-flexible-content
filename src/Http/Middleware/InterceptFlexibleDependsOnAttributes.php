<?php

namespace Whitecube\NovaFlexibleContent\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\FieldCollection;
use Laravel\Nova\Http\Requests\ActionRequest;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Http\Requests\ResourceCreateOrAttachRequest;
use Laravel\Nova\Http\Requests\ResourceUpdateOrUpdateAttachedRequest;
use Laravel\Nova\Http\Resources\CreateViewResource;
use Laravel\Nova\Http\Resources\CreationPivotFieldResource;
use Laravel\Nova\Http\Resources\ReplicateViewResource;
use Laravel\Nova\Http\Resources\UpdatePivotFieldResource;
use Laravel\Nova\Http\Resources\UpdateViewResource;
use Symfony\Component\HttpFoundation\Response;
use Whitecube\NovaFlexibleContent\Flexible;
use Whitecube\NovaFlexibleContent\Http\FlexibleAttribute;
use Whitecube\NovaFlexibleContent\Layouts\LayoutInterface;
use \Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class InterceptFlexibleDependsOnAttributes
{
    /**
     * Handle the given request and get the response.
     *
     * This middleware transforms flexible content field names in dependsOn requests
     * by stripping the group key prefix so that the dependsOn callbacks receive
     * the original field names they expect.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldTransformRequest($request)) {
            return $next($request);
        }

        $this->transformFlexibleFieldNames($request);

        return $next($request);
    }

    /**
     * Determine if the request should be transformed.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function shouldTransformRequest(Request $request): bool
    {
        // Only intercept Nova's dependent field requests
        if (! $this->isDependentFieldRequest($request)) {
            return false;
        }

        // Only transform if the request contains flexible content field names
        return $this->hasFlexibleFieldNames($request);
    }

    /**
     * Check if this is a Nova dependent field request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function isDependentFieldRequest(Request $request): bool
    {
        return $request->isMethod(SymfonyRequest::METHOD_PATCH)
            && $this->getNovaRequestInstance($request) !== null;
    }

    /**
     * Check if the request contains flexible content field names.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function hasFlexibleFieldNames(Request $request): bool
    {
        $fields = $this->getNovaRequestFields($request);

        $jsonKeys = $request->json() ? $request->json()->keys() : [];
        $inputFields = array_filter(array_merge($jsonKeys, [$request->get('field')]));

        foreach ($inputFields as $inputField) {
            if (! $this->hasGroupPrefix($inputField)) {
                continue;
            }
            $newAttribute = $this->stripGroupPrefix($inputField);
            $matchedField = $this->findFieldByAttribute($fields, $newAttribute);

            if ($matchedField) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the appropriate Nova request instance based on the request path.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Laravel\Nova\Http\Requests\NovaRequest|null
     */
    protected function getNovaRequestInstance(Request $request): ?NovaRequest
    {
        $path = $request->path();

        $pattern = '/nova-api\/[^\/]+(?:\/\d+)?\/(creation-fields|update-fields|creation-pivot-fields\/[^\/]+|update-pivot-fields\/[^\/]+(?:\/\d+)?|action)/';
        if (preg_match($pattern, $path, $matches) === 1) {
            $route = $matches[1];
            if (strpos($route, 'creation-fields') !== false || strpos($route, 'creation-pivot-fields') !== false) {
                return app(ResourceCreateOrAttachRequest::class);
            } elseif (strpos($route, 'update-fields') !== false || strpos($route, 'update-pivot-fields') !== false) {
                return app(ResourceUpdateOrUpdateAttachedRequest::class);
            } elseif ($route === 'action') {
                return app(ActionRequest::class);
            }
        }

        return null;
    }

    /**
     * Check if the request is for a pivot resource.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function isPivotRequest(Request $request): bool
    {
        $path = $request->path();
        return strpos($path, 'creation-pivot-fields') !== false
            || strpos($path, 'update-pivot-fields') !== false;
    }

    protected function getNovaRequestFields(Request $request): array
    {
        $novaRequest = $this->getNovaRequestInstance($request);

        if (is_null($novaRequest)) {
            return [];
        }

        $class = get_class($novaRequest);

        switch ($class) {
            case ResourceCreateOrAttachRequest::class:
                return $this->getCreateRequestFields($novaRequest);
            case ResourceUpdateOrUpdateAttachedRequest::class:
                return $this->getUpdateRequestFields($novaRequest);
            case ActionRequest::class:
                return $this->getActionRequestFields($novaRequest);
            default:
                return [];
        }
    }

    protected function getCreateRequestFields(ResourceCreateOrAttachRequest $request): array
    {
        // Check if this is a pivot resource request
        if ($this->isPivotRequest($request)) {
            $resource = CreationPivotFieldResource::make()->newResourceWith($request);
            return $resource->creationPivotFields($request, $request->relatedResource)->all();
        }

        // Regular resource request
        $resource = $request->has('fromResourceId')
            ? ReplicateViewResource::make($request->fromResourceId)->newResourceWith($request)
            : CreateViewResource::make()->newResourceWith($request);

        return $resource->creationFields($request)->all();
    }

    protected function getUpdateRequestFields(ResourceUpdateOrUpdateAttachedRequest $request): array
    {
        // Check if this is a pivot resource request
        if ($this->isPivotRequest($request)) {
            $resource = UpdatePivotFieldResource::make()->newResourceWith($request);
            return $resource->updatePivotFields($request, $request->relatedResource)->all();
        }

        // Regular resource request
        $resource = UpdateViewResource::make()->newResourceWith($request);
        return $resource->updateFields($request)->all();
    }

    protected function getActionRequestFields(ActionRequest $request): array
    {
        return $request->action()->fields($request);

    }

    public function findFieldByAttribute(array $fields, string $attribute): ?Field
    {
        $matchedField = FieldCollection::make($fields)->findFieldByAttribute($attribute);
        if (!is_null($matchedField)) {
            return $matchedField;
        }

        foreach ($fields as $field) {
            if ($field instanceof Flexible) {
                $closure = function () {
                    return $this->layouts;
                };
                $layouts = $closure->call($field);
                /** @var LayoutInterface $layout */
                foreach ($layouts as $layout) {
                    $matchedField = $this->findFieldByAttribute($layout->fields(), $attribute);
                    if (!is_null($matchedField)) {
                        return $matchedField;
                    }
                }
            } elseif (method_exists($field, 'getFields')) {
                $matchedField = $this->findFieldByAttribute($field->getFields(), $attribute);
                if (!is_null($matchedField)) {
                    return $matchedField;
                }
            }
        }

        return null;
    }

    /**
     * Transform flexible field names by stripping the group key prefix.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    protected function transformFlexibleFieldNames(Request $request): void
    {
        // Transform the 'field' query parameter if present
        $fieldName = $request->get('field');
        $newFieldName = $this->stripGroupPrefix($fieldName);

        $fields = $this->getNovaRequestFields($request);

        $matchedField = $this->findFieldByAttribute($fields, $newFieldName);

        if (! is_null($fieldName) && ! is_null($matchedField)
            && strpos($fieldName, FlexibleAttribute::GROUP_SEPARATOR) !== false) {
            $request->query->set('field', $newFieldName);
        }

        // Transform all input field names
        $transformedInput = $this->transformInputFields($fields, $request->all());

        if (! empty($transformedInput)) {
            $request->merge($transformedInput);
        }
    }

    /**
     * Transform input fields by stripping flexible group prefixes.
     *
     * @param  array  $input
     * @return array
     */
    protected function transformInputFields(array $fields , array $input): array
    {
        $transformed = [];

        foreach ($input as $key => $value) {
            if (strpos($key, FlexibleAttribute::GROUP_SEPARATOR) === false) {
                continue;
            }

            $newKey = $this->stripGroupPrefix($key);
            if ($this->findFieldByAttribute($fields, $newKey)) {
                $transformed[$newKey] = $value;
            }
        }

        return $transformed;
    }

    /**
     * Check if a field name has the flexible group prefix pattern.
     *
     * @param  string  $fieldName
     * @return bool
     */
    protected function hasGroupPrefix(string $fieldName): bool
    {
        $separator = preg_quote(FlexibleAttribute::GROUP_SEPARATOR, '/');
        $pattern = '/^c[A-Za-z0-9]{15}' . $separator . '(.+)$/';

        return preg_match($pattern, $fieldName) === 1;
    }

    /**
     * Strip the flexible group prefix from a field name.
     * Only strips if the field name matches the flexible field pattern: c[A-Za-z0-9]{15}__field_name
     *
     * @param  string  $fieldName
     * @return string
     */
    protected function stripGroupPrefix(string $fieldName): string
    {
        if (!$this->hasGroupPrefix($fieldName)) {
            return $fieldName;
        }

        // Extract the field name without the group prefix
        $separator = preg_quote(FlexibleAttribute::GROUP_SEPARATOR, '/');
        $pattern = '/^c[A-Za-z0-9]{15}' . $separator . '(.+)$/';

        preg_match($pattern, $fieldName, $matches);

        // Return just the field name without the group prefix
        return $matches[1];
    }
}
