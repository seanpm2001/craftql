<?php

namespace markhuot\CraftQL\Services;

use Craft;
use Egulias\EmailValidator\Exception\CRLFAtTheEnd;
use GraphQL\GraphQL;
use GraphQL\Error\Debug;
use GraphQL\Type\Schema;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;
use markhuot\CraftQL\CraftQL;
use markhuot\CraftQL\Events\AlterQuerySchema;
use markhuot\CraftQL\Types\ElementInterface;
use markhuot\CraftQL\Types\EntryConnection;
use markhuot\CraftQL\Types\EntryDraftConnection;
use markhuot\CraftQL\Types\EntryDraftEdge;
use markhuot\CraftQL\Types\EntryDraftInfo;
use markhuot\CraftQL\Types\EntryEdge;
use markhuot\CraftQL\Types\EntryInterface;
use markhuot\CraftQL\Types\EntryType;
use markhuot\CraftQL\Types\Field;
use markhuot\CraftQL\Types\PageInfo;
use markhuot\CraftQL\Types\Section;
use markhuot\CraftQL\Types\SectionSiteSettings;
use markhuot\CraftQL\Types\Site;
use markhuot\CraftQL\Types\Timestamp;
use markhuot\CraftQL\Types\User;
use yii\base\Component;
use Yii;

class GraphQLService extends Component {

    private $schema;
    private $volumes;
    private $categoryGroups;
    private $tagGroups;
    private $entryTypes;
    private $sections;
    private $globals;

    function __construct(
        \markhuot\CraftQL\Repositories\Volumes $volumes,
        \markhuot\CraftQL\Repositories\CategoryGroup $categoryGroups,
        \markhuot\CraftQL\Repositories\TagGroup $tagGroups,
        \markhuot\CraftQL\Repositories\EntryType $entryTypes,
        \markhuot\CraftQL\Repositories\Section $sections,
        \markhuot\CraftQL\Repositories\Globals $globals
    ) {
        $this->volumes = $volumes;
        $this->categoryGroups = $categoryGroups;
        $this->tagGroups = $tagGroups;
        $this->entryTypes = $entryTypes;
        $this->sections = $sections;
        $this->globals = $globals;
    }

    /**
     * Bootstrap the schema
     *
     * @return void
     */
    function bootstrap() {
        $this->volumes->load();
        $this->categoryGroups->load();
        $this->tagGroups->load();
        $this->entryTypes->load();
        $this->sections->load();
        $this->globals->load();

        // @TODO don't load _everything_. Instead only load what's needed on demand
        \Yii::$container->get('craftQLFieldService')->load();

        $maxQueryDepth = CraftQL::getInstance()->getSettings()->maxQueryDepth;
        if ($maxQueryDepth !== false) {
            $rule = new QueryDepth($maxQueryDepth);
            DocumentValidator::addRule($rule);
        }

        $maxQueryComplexity = CraftQL::getInstance()->getSettings()->maxQueryComplexity;
        if ($maxQueryComplexity !== false) {
            $rule = new QueryComplexity($maxQueryComplexity);
            DocumentValidator::addRule($rule);
        }
    }

    function getSchema($token) {
        $request = new \markhuot\CraftQL\Request($token);
        $request->addCategoryGroups(new \markhuot\CraftQL\Factories\CategoryGroup($this->categoryGroups, $request));
        $request->addEntryTypes(new \markhuot\CraftQL\Factories\EntryType($this->entryTypes, $request));
        $request->addVolumes(new \markhuot\CraftQL\Factories\Volume($this->volumes, $request));
        $request->addSections(new \markhuot\CraftQL\Factories\Section($this->sections, $request));
        $request->addTagGroups(new \markhuot\CraftQL\Factories\TagGroup($this->tagGroups, $request));
        $request->addGlobals(new \markhuot\CraftQL\Factories\Globals($this->globals, $request));

        $schemaConfig = [];

        $query = new \markhuot\CraftQL\Types\Query($request);

        $event = new AlterQuerySchema;
        $event->query = $query;
        $query->trigger(AlterQuerySchema::EVENT, $event);

        $request->registerNamespace('\\markhuot\\CraftQL\\Types');

        $request->registerType('Query', $query);

        array_map(function ($entryType) use ($request) {
            $request->registerType($entryType->getName(), $entryType);
        }, $request->entryTypes()->all());

        array_map(function ($volume) use ($request) {
            $request->registerType($volume->getName(), $volume);
        }, $request->volumes()->all());

        array_map(function ($globalSet) use ($request) {
            $request->registerType($globalSet->getName(), $globalSet);
        }, $request->globals()->all());

        array_map(function ($categoryGroup) use ($request) {
            $request->registerType($categoryGroup->getName(), $categoryGroup);
        }, $request->categoryGroups()->all());

        $request->registerType('DateFormatTypes', \markhuot\CraftQL\Directives\Date::dateFormatTypesEnum());

        $schemaConfig['types'] = function () use ($request) {
            $types = [];

            $types = array_merge($types, array_map(function ($entryType) use ($request) {
                return $request->getType($entryType->getName());
            }, $request->entryTypes()->all()));

            $types = array_merge($types, array_map(function ($volume) use ($request) {
                return $request->getType($volume->getName());
            }, $request->volumes()->all()));

            $types = array_merge($types, array_map(function ($categoryGroup) use ($request) {
                return $request->getType($categoryGroup->getName());
            }, $request->categoryGroups()->all()));

            $types = array_merge($types, array_map(function ($tagGroup) use ($request) {
                return $request->getType($tagGroup->getName());
            }, $request->tagGroups()->all()));

            $types = array_merge($types, array_map(function ($section) use ($request) {
                return $request->getType($section->getName());
            }, $request->sections()->all()));

            $types[] = $request->getType('DateFormatTypes');

            $types = array_merge($types, $request->getTypeBuilder('Query')->getConcreteTypes());

            return $types;
        };

        $schemaConfig['query'] = $request->getType('Query');

        $schemaConfig['typeLoader'] = function ($name) use ($request) {
            if ($request->getType($name)) {
                return $request->getType($name);
            }

            throw new \Exception($name.' could not be found');
        };

        $schemaConfig['directives'] = array_merge(GraphQL::getStandardDirectives(), [
            \markhuot\CraftQL\Directives\Date::directive(),
        ]);

        // $mutation = (new \markhuot\CraftQL\Types\Mutation($request))->getRawGraphQLObject();
        // $schemaConfig['mutation'] = $mutation;

        $schema = new Schema($schemaConfig);

        // if (Craft::$app->config->general->devMode) {
        //     $schema->assertValid();
        // }

        return $schema;
    }

    function execute($schema, $input, $variables = []) {
        $debug = Craft::$app->config->getGeneral()->devMode ? Debug::INCLUDE_DEBUG_MESSAGE | Debug::RETHROW_INTERNAL_EXCEPTIONS : null;
        return GraphQL::executeQuery($schema, $input, null, null, $variables)->toArray($debug);
    }

}
