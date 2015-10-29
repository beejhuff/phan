<?php
declare(strict_types=1);
namespace Phan\Language;

require_once(__DIR__.'/../Deprecated/AST.php');

use \Phan\CodeBase;
use \Phan\Configuration;
use \Phan\Language\Element\Clazz;
use \Phan\Language\Element\Comment;
use \Phan\Language\Element\Method;
use \Phan\Language\Element\Property;
use \Phan\Log;

class File {

    /**
     * @var CodeBase
     */
    private $code_base = null;

    /**
     * @var string
     */
    private $file = null;

    /**
     * @var \ast
     */
    private $ast = null;

    /**
     * @param string $file
     */
    public function __construct(
        CodeBase $code_base,
        string $file
    ) {
        $this->code_base = $code_base;
        $this->file = $file;
        $this->ast = \ast\parse_file($file);
    }

    /**
     * @return string
     * The namespace of the file
     */
    public function passOne() {
        return $this->passOneRecursive(
            $this->ast,
            (new Context())
                ->withFile($this->file)
                ->withLineNumberStart($this->ast->lineno ?? 0)
                ->withLineNumberEnd($this->ast->endLineno ?? 0)
        );
    }

    /**
     * @param \ast\Node $ast
     *
     * @param Context $context
     *
     * @param CodeBase $code_base
     *
     * @return string
     * The namespace of the file
     */
    public function passOneRecursive(
        \ast\Node $ast,
        Context $context
    ) : Context {
        $done = false;

        $current_clazz = $context->isInClassScope()
            ? $this->code_base->getClassByFQSEN(
                $context->getClassFQSEN()
            )
            : null;

        switch($ast->kind) {
            case \ast\AST_NAMESPACE:
                $context = $context->withNamespace(
                    (string)$ast->children[0].'\\'
                );
                break;

            case \ast\AST_IF:
                $context->setIsConditional(true);
                $this->code_base->incrementConditionals();
                break;

            case \ast\AST_DIM:
                if (!Configuration::instance()->bc_checks) {
                    break;
                }

                if(!($ast->children[0] instanceof \ast\Node
                    && $ast->children[0]->children[0] instanceof \ast\Node)
                ) {
                    break;
                }

                // check for $$var[]
                if($ast->children[0]->kind == \ast\AST_VAR
                    && $ast->children[0]->children[0]->kind == \ast\AST_VAR
                ) {
                    $temp = $ast->children[0]->children[0];
                    $depth = 1;
                    while($temp instanceof \ast\Node) {
                        $temp = $temp->children[0];
                        $depth++;
                    }
                    $dollars = str_repeat('$',$depth);
                    $ftemp = new \SplFileObject($file);
                    $ftemp->seek($ast->lineno-1);
                    $line = $ftemp->current();
                    unset($ftemp);
                    if(strpos($line,'{') === false
                        || strpos($line,'}') === false
                    ) {
                        Log::err(
                            Log::ECOMPAT,
                            "{$dollars}{$temp}[] expression may not be PHP 7 compatible",
                            $file,
                            $ast->lineno
                        );
                    }
                }

                // $foo->$bar['baz'];
                else if(!empty($ast->children[0]->children[1]) && ($ast->children[0]->children[1] instanceof \ast\Node) && ($ast->children[0]->kind == \ast\AST_PROP) &&
                        ($ast->children[0]->children[0]->kind == \ast\AST_VAR) && ($ast->children[0]->children[1]->kind == \ast\AST_VAR)) {
                    $ftemp = new \SplFileObject($file);
                    $ftemp->seek($ast->lineno-1);
                    $line = $ftemp->current();
                    unset($ftemp);
                    if(strpos($line,'{') === false
                        || strpos($line,'}') === false
                    ) {
                        Log::err(
                            Log::ECOMPAT,
                            "expression may not be PHP 7 compatible",
                            $file,
                            $ast->lineno
                        );
                    }
                }

            case \ast\AST_USE:
                foreach($ast->children as $elem) {
                    $target = $elem->children[0];
                    if(empty($elem->children[1])) {
                        if(($pos=strrpos($target, '\\'))!==false) {
                            $alias = substr($target, $pos + 1);
                        } else {
                            $alias = $target;
                        }
                    } else {
                        $alias = $elem->children[1];
                    }

                    $context = $context->withNamespaceMap(
                        $ast->flags, $alias, $target
                    );
                }
                break;

            case \ast\AST_CLASS:
                // Get an FQSEN for this class
                $class_name = $ast->name;
                $class_fqsen = FQSEN::fromContext($context)
                    ->withClassName($class_name);

                // Hunt for an available alternate ID if necessary
                $alternate_id = 0;
                while($this->code_base->classExists($class_fqsen)) {
                    $class_fqsen = $class_fqsen->withAlternateId(
                        ++$alternate_id
                    );
                }

                // Update the context to signal that we're now
                // within a class context.
                $context = $context->withClassFQSEN($class_fqsen);

                /*
                if(!empty($classes[strtolower($context->getNamespace().$ast->name)])) {
                    for($i=1;;$i++) {
                        if(empty($classes[$i.":".strtolower($namespace.$ast->name)])) break;
                    }
                    $context = $context->withClassFQSEN(
                        (new FQSEN(
                            [],
                            $context->getNamespace(),
                            $ast->name,
                            ''
                        ))->withAlternateId($i)
                    );
                } else {
                    $context = $context->withClassFQSEN(
                        new FQSEN(
                            [],
                            $context->getNamespace(),
                            $ast->name,
                            '')
                    );
                }
                 */

                if(!empty($ast->children[0])) {
                    $parent_class_name = $ast->children[0]->children[0];

                    if($ast->children[0]->flags & \ast\flags\NAME_NOT_FQ) {
                        if(($pos = strpos($parent,'\\')) !== false) {

                            if ($context->hasNamespaceMapFor(
                                T_CLASS,
                                substr($parent, 0, $pos)
                            )) {
                                $parent_class_name =
                                    $context->getNamespaceMapfor(
                                        T_CLASS,
                                        substr($parent, 0, $pos)
                                    );
                            }
                        }
                    }
                }


                if(!empty($ast->children[0])) {
                    $parent = $ast->children[0]->children[0];
                    if($ast->children[0]->flags & \ast\flags\NAME_NOT_FQ) {
                        if(($pos = strpos($parent,'\\')) !== false) {
                            // extends A\B
                            // check if we have a namespace alias for A
                            if(!empty($namespace_map[T_CLASS][$file][strtolower(substr($parent,0,$pos))])) {
                                $parent = $namespace_map[T_CLASS][$file][strtolower(substr($parent,0,$pos))] . substr($parent,$pos);
                                goto done;
                            }
                        }
                        $parent = $namespace_map[T_CLASS][$file][strtolower($parent)] ?? $namespace.$parent;
                        done:
                    }
                } else {
                    $parent = null;
                }

                $current_clazz = new Clazz(
                    $context
                        ->withLineNumberStart($ast->lineno)
                        ->withLineNumberEnd($ast->endLineno ?: -1),
                    Comment::fromString($ast->docComment ?: ''),
                    $ast->name,
                    new Type([$ast->name]),
                    $ast->flags
                );

                $this->code_base->addClass($current_clazz);
                $this->code_base->incrementClasses();

                /*
                $lc = $context->getClassFQSEN();
                $classes[$lc] = [
                    'file'		 => $file,
                    'namespace'	 => $namespace,
                    'conditional'=> $is_conditional,
                    'flags'		 => $ast->flags,
                    'lineno'	 => $ast->lineno,
                    'endLineno'  => $ast->endLineno,
                    'name'		 => $namespace.$ast->name,
                    'docComment' => $ast->docComment,
                    'parent'	 => $parent,
                    'pc_called'  => true,
                    'type'	     => '',
                    'properties' => [],
                    'constants'  => [],
                    'traits'	 => [],
                    'interfaces' => [],
                    'methods'	 => []
                ];
                */

                /*
                $classes[$lc]['interfaces'] = array_merge(
                    $classes[$lc]['interfaces'],
                    node_namelist($file, $ast->children[1], $namespace)
                );
                 */


                break;

            case \ast\AST_USE_TRAIT:

                $trait_name_list =
                    node_namelist(
                        $context->getFile(),
                        $ast->children[0],
                        $context->getNamespace()
                    );

                foreach ($trait_name_list as $trait_name) {
                    $current_clazz->addTraintFQSEN(
                        FQSEN::fromContext(
                            $context
                        )->withClassName($trait_name)
                    );
                }

                /*
                $classes[$lc]['traits'] =
                    array_merge(
                        $classes[$lc]['traits'],
                        node_namelist(
                            $file,
                            $ast->children[0],
                            $namespace
                        )
                    );
                 */

                $this->code_base->incrementTraits();
                break;

            case \ast\AST_METHOD:
                $method_name = $ast->name;

                $method_fqsen = FQSEN::fromContext(
                    $context
                )->withMethodName($method_name);

                // Hunt for an available alternate ID if necessary
                $alternate_id = 0;
                while($current_clazz->hasMethodWithFQSEN($method_fqsen)) {
                    $method_fqsen = $method_fqsen->withAlternateId(
                        ++$alternate_id
                    );
                }

                $method =
                    new Method(
                        $context
                            ->withLineNumberStart($ast->lineno ?: 0)
                            ->withLineNumberEnd($ast->endLineno ?? -1),
                        Comment::fromString($ast->docComment ?: ''),
                        $method_name,
                        Type::none(),
                        0, // flags
                        0, // number_of_required_parameters
                        0  // number_of_optional_parameters
                    );

                $current_clazz->addMethod($method);
                $this->code_base->incrementMethods();

                $context = $context->withMethodFQSEN(
                    $method->getFQSEN()
                );

                if ('__construct' == $method_name) {
                    $current_clazz->setIsParentConstructorCalled(false);
                }

                if ('__invoke' == $method_name) {
                    $current_clazz->getType()->addTypeName('callable');
                }

                /*
                if($method == '__construct')
                    $classes[$lc]['pc_called'] = false;
                if($method == '__invoke')
                    $classes[$lc]['type'] =
                        merge_type($classes[$lc]['type'], 'callable');
                 */

                /*
                // if(!empty($classes[$lc]['methods'][strtolower($ast->name)])) {
                    for($i=1;;$i++) {
                        if(empty($classes[$lc]['methods'][$i.':'.strtolower($ast->name)])) break;
                    }
                    $method = $i.':'.$ast->name;
                } else {
                    $method = $ast->name;
                }
                $classes[$lc]['methods'][strtolower($method)] =
                    MethodElement::fromAST(
                        $this->file,
                        $is_conditional,
                        $ast,
                        "{$current_class}::{$method}",
                        $current_class,
                        $namespace
                    );
                $this->code_base->incrementMethods();
                $current_function = $method;
                $current_scope = "{$current_class}::{$method}";
                if($method == '__construct') $classes[$lc]['pc_called'] = false;
                if($method == '__invoke') $classes[$lc]['type'] = merge_type($classes[$lc]['type'], 'callable');
                break;
                 */

            case \ast\AST_PROP_DECL:
                if(empty($context->getClassFQSEN())) {
                    Log::err(
                        Log::EFATAL,
                        "Invalid property declaration",
                        $context->getFile(),
                        $ast->lineno
                    );
                }

                $comment = Comment::fromString($ast->docComment ?? '');
                /*
                $dc = null;
                if(!empty($ast->docComment)) {
                    $dc = parse_doc_comment($ast->docComment);
                }
                 */

                foreach($ast->children as $i=>$node) {
                    // Ignore children which are not property elements
                    if (!$node || $node->kind != \ast\AST_PROP_ELEM) {
                        continue;
                    }

                    // @var Type
                    $type = Type::typeFromNode(
                        $context,
                        $node->children[1]
                    );

                    /*
                    $type =
                        node_type(
                            $file,
                            $namespace,
                            $node->children[1],
                            $current_scope,
                            empty($classes[$lc]) ? null : $classes[$lc]
                        );
                     */

                    $property_name = $node->children[0];

                    assert(is_string($property_name),
                        'Property name must be a string. '
                        . 'Got '
                        . print_r($property_name, true)
                        . ' at '
                        . $context);

                    $current_clazz->addProperty(
                        new Property(
                            $context
                                ->withLineNumberStart($node->lineno)
                                ->withLineNumberEnd($node->endLineno ?? -1),
                            Comment::fromString($node->docComment ?? ''),
                            is_string($node->children[0])
                                ? $node->children[0]
                                : '_error_',
                            $type,
                            $ast->flags
                        )
                    );

                    /*
                    $classes[$lc]['properties'][$node->children[0]] = [
                            'flags'=>$ast->flags,
                            'name'=>$node->children[0],
                            'lineno'=>$node->lineno
                        ];
                     */

                    if(!empty($dc['vars'][$i]['type'])) {
                        if($type !=='null' && !type_check($type, $dc['vars'][$i]['type'])) {
                            Log::err(Log::ETYPE, "property is declared to be {$dc['vars'][$i]['type']} but was assigned $type", $file, $node->lineno);
                        }
                        // Set the declarted type to the doc-comment type and add |null if the default value is null
                        $classes[$lc]['properties'][$node->children[0]]['dtype'] = $dc['vars'][$i]['type'] . (($type==='null')?'|null':'');
                        $classes[$lc]['properties'][$node->children[0]]['type'] = $dc['vars'][$i]['type'];
                        if(!empty($type) && $type != $classes[$lc]['properties'][$node->children[0]]['type']) {
                            $classes[$lc]['properties'][$node->children[0]]['type'] = merge_type($classes[$lc]['properties'][$node->children[0]]['type'], strtolower($type));
                        }
                    } else {

                        $property_name = $node->children[0];

                        assert(is_string($property_name),
                            'Property name must be a string. Got ' . print_r($property_name, true) . ' at ' . $context->__toString());

                        if ($current_clazz->hasPropertyWithName($property_name)) {
                            $property =
                                $current_clazz->getPropertyWithName($property_name);
                            $property->setDType(Type::none());
                            $property->setType($type);

                            /*
                            $classes[$lc]['properties'][$node->children[0]]['dtype'] = '';
                            $classes[$lc]['properties'][$node->children[0]]['type'] = $type;
                            */
                        }
                    }
                }
                $done = true;
                break;

            case \ast\AST_CLASS_CONST_DECL:
                if(empty($current_class)) Log::err(Log::EFATAL, "Invalid constant declaration", $file, $ast->lineno);

                foreach($ast->children as $node) {
                    $classes[$lc]['constants'][$node->children[0]] = [
                    'name'=>$node->children[0],
                    'lineno'=>$node->lineno,
                    'type'=>node_type($file, $namespace, $node->children[1], $current_scope, empty($classes[$lc]) ? null : $classes[$lc])
                    ];
                }
                $done = true;
                break;

            case \ast\AST_FUNC_DECL:
                $function_name = strtolower($context->getNamespace() . $ast->name);
                if(!empty($functions[$function_name])) {
                    for($i=1;;$i++) {
                        if(empty($functions[$i.":".$function_name])) break;
                    }
                    $function_name = $i.':'.$function_name;
                }

                $this->code_base->addMethod(
                    Method::fromAST(
                        $context
                            ->withLineNumberStart($ast->lineno ?? 0)
                            ->withLineNumberEnd($ast->endLineno ?? 0),
                        $ast
                    )
                );

                /*
                $functions[$function_name] =
                    node_func(
                        $file,
                        $is_conditional,
                        $ast,
                        $function,
                        $current_class,
                        $namespace
                    );
                 */

                $this->code_base->incrementFunctions();
                $context->setFunctionName($function_name);
                $context->setScope($function_name);

                // Not $done=true here since nested function declarations are allowed
                break;

            case \ast\AST_CLOSURE:
                $this->code_base->incrementClosures();
                $current_scope = "{closure}";
                break;

            case \ast\AST_CALL: // Looks odd to check for AST_CALL in pass1, but we need to see if a function calls func_get_arg/func_get_args/func_num_args
                $found = false;
                $call = $ast->children[0];
                if($call->kind == \ast\AST_NAME) {
                    $func_name = strtolower($call->children[0]);
                    if($func_name == 'func_get_args' || $func_name == 'func_get_arg' || $func_name == 'func_num_args') {
                        if(!empty($current_class)) {
                            $classes[$lc]['methods'][strtolower($current_function)]['optional'] = 999999;
                        } else {
                            $functions[strtolower($current_function)]['optional'] = 999999;
                        }
                    }
                }
                if(Configuration::instance()->bc_checks) {
                    \Phan\Deprecated::bc_check(
                        $context->getFile(),
                        $ast
                    );
                }
                break;

            case \ast\AST_STATIC_CALL: // Indicate whether a class calls its parent constructor
                $call = $ast->children[0];
                if($call->kind == \ast\AST_NAME) {
                    $func_name = strtolower($call->children[0]);
                    if($func_name == 'parent') {
                        $meth = strtolower($ast->children[1]);
                        if($meth == '__construct') {
                            $classes[strtolower($current_class)]['pc_called'] = true;
                        }
                    }
                }
                break;

            case \ast\AST_RETURN:
            case \ast\AST_PRINT:
            case \ast\AST_ECHO:
            case \ast\AST_STATIC_CALL:
            case \ast\AST_METHOD_CALL:
                if($bc_checks) {
                    bc_check($file, $ast);
                }
                break;
        }

        if(!$done) {
            foreach($ast->children as $child) {
                if ($child instanceof \ast\Node) {
                    $child_context =
                        $this->passOneRecursive(
                            $child,
                            $context
                        );

                    $context = $context->withNamespace(
                        $child_context->getNamespace()
                    );
                }
            }
        }

        return $context;
    }

}