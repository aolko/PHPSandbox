<?php
    /** ValidatorVisitor class declaration
     * @package PHPSandbox
     */
    namespace PHPSandbox;

    /**
     * Validator class for PHP Sandboxes.
     *
     * This class takes parsed AST code and checks it against the passed PHPSandbox instance
     * configuration for errors, and throws exceptions if they are found
     *
     * @namespace PHPSandbox
     *
     * @author  Elijah Horton <fieryprophet@yahoo.com>
     * @version 1.3.1
     */
    class ValidatorVisitor extends \PHPParser_NodeVisitorAbstract {
        /** The PHPSandbox instance to check against
         * @var PHPSandbox
         */
        protected $sandbox;
        /** ValidatorVisitor class constructor
         *
         * This constructor takes a passed PHPSandbox instance to check against for validating sandboxed code.
         *
         * @param   PHPSandbox   $sandbox            The PHPSandbox instance to check against
         */
        public function __construct(PHPSandbox $sandbox){
            $this->sandbox = $sandbox;
        }
        /** Examine the current PHPParser_Node node against the PHPSandbox configuration for validating sandboxed code
         *
         * @param   \PHPParser_Node   $node          The sandboxed $node to validate
         *
         * @throws  Error             Throws an exception if validation fails
         *
         * @return  \PHPParser_Node|bool|null        Return rewritten node, false if node must be removed, or null if no changes to the node are made
         */
        public function leaveNode(\PHPParser_Node $node){
            if($node instanceof \PHPParser_Node_Stmt_InlineHTML){
                if(!$this->sandbox->allow_escaping){
                    $this->sandbox->error("Sandboxed code attempted to escape to HTML!", Error::ESCAPE_ERROR, $node);
                }
            } else if($node instanceof \PHPParser_Node_Expr_Cast){
                if(!$this->sandbox->allow_casting){
                    $this->sandbox->error("Sandboxed code attempted to cast!", Error::CAST_ERROR, $node);
                }
            } else if($node instanceof \PHPParser_Node_Expr_FuncCall){
                if($node->name instanceof \PHPParser_Node_Name){
                    $name = strtolower($node->name->toString());
                    if(!$this->sandbox->check_func($name)){
                        $this->sandbox->error("Function failed custom validation!", Error::VALID_FUNC_ERROR, $node);
                    }
                    if($this->sandbox->is_defined_func($name)){
                        $args = $node->args;
                        array_unshift($args, new \PHPParser_Node_Arg(new \PHPParser_Node_Scalar_String($name)));
                        return new \PHPParser_Node_Expr_MethodCall(new \PHPParser_Node_Expr_StaticCall(new \PHPParser_Node_Name_FullyQualified("PHPSandbox\\PHPSandbox"), 'getSandbox', array(new \PHPParser_Node_Scalar_String($this->sandbox->name))), 'call_func', $args, $node->getAttributes());
                    }
                    if($this->sandbox->overwrite_defined_funcs && in_array($name, PHPSandbox::$defined_funcs)){
                        return new \PHPParser_Node_Expr_MethodCall(new \PHPParser_Node_Expr_StaticCall(new \PHPParser_Node_Name_FullyQualified("PHPSandbox\\PHPSandbox"), 'getSandbox', array(new \PHPParser_Node_Scalar_String($this->sandbox->name))), '_' . $name, array(new \PHPParser_Node_Arg(new \PHPParser_Node_Expr_FuncCall(new \PHPParser_Node_Name(array($name))))), $node->getAttributes());
                    }
                    if($this->sandbox->overwrite_var_funcs && in_array($name, PHPSandbox::$var_funcs)){
                        $args = $node->args;
                        return new \PHPParser_Node_Expr_MethodCall(new \PHPParser_Node_Expr_StaticCall(new \PHPParser_Node_Name_FullyQualified("PHPSandbox\\PHPSandbox"), 'getSandbox', array(new \PHPParser_Node_Scalar_String($this->sandbox->name))), '_' . $name, $args, $node->getAttributes());
                    }
                    if($this->sandbox->overwrite_func_get_args && in_array($name, PHPSandbox::$arg_funcs)){
                        if($name == 'func_get_arg'){
                            $index = new \PHPParser_Node_Arg(new \PHPParser_Node_Scalar_LNumber(0));
                            if(isset($node->args[0]) && $node->args[0] instanceof \PHPParser_Node_Arg){
                                $index = $node->args[0];
                            }
                            return new \PHPParser_Node_Expr_MethodCall(new \PHPParser_Node_Expr_StaticCall(new \PHPParser_Node_Name_FullyQualified("PHPSandbox\\PHPSandbox"), 'getSandbox', array(new \PHPParser_Node_Scalar_String($this->sandbox->name))), '_' . $name, array(new \PHPParser_Node_Arg(new \PHPParser_Node_Expr_FuncCall(new \PHPParser_Node_Name(array('func_get_args')))), $index), $node->getAttributes());
                        }
                        return new \PHPParser_Node_Expr_MethodCall(new \PHPParser_Node_Expr_StaticCall(new \PHPParser_Node_Name_FullyQualified("PHPSandbox\\PHPSandbox"), 'getSandbox', array(new \PHPParser_Node_Scalar_String($this->sandbox->name))), '_' . $name, array(new \PHPParser_Node_Arg(new \PHPParser_Node_Expr_FuncCall(new \PHPParser_Node_Name(array('func_get_args'))))), $node->getAttributes());
                    }
                } else {
                    return new \PHPParser_Node_Expr_Ternary(
                        new \PHPParser_Node_Expr_MethodCall(new \PHPParser_Node_Expr_StaticCall(new \PHPParser_Node_Name_FullyQualified("PHPSandbox\\PHPSandbox"), 'getSandbox', array(new \PHPParser_Node_Scalar_String($this->sandbox->name))), 'check_func', array(new \PHPParser_Node_Arg($node->name)), $node->getAttributes()),
                        $node,
                        new \PHPParser_Node_Expr_ConstFetch(new \PHPParser_Node_Name('null'))
                    );
                }
            } else if($node instanceof \PHPParser_Node_Stmt_Function){
                if(!$this->sandbox->allow_functions){
                    $this->sandbox->error("Sandboxed code attempted to define function!", Error::DEFINE_FUNC_ERROR, $node);
                }
                if(!$this->sandbox->check_keyword('function')){
                    $this->sandbox->error("Keyword failed custom validation!", Error::VALID_KEYWORD_ERROR, $node, 'function');
                }
                if(!$node->name){
                    $this->sandbox->error("Sandboxed code attempted to define unnamed function!", Error::DEFINE_FUNC_ERROR, $node, '');
                }
                if($this->sandbox->is_defined_func($node->name)){
                    $this->sandbox->error("Sandboxed code attempted to redefine function!", Error::DEFINE_FUNC_ERROR, $node, $node->name);
                }
                if($node->byRef && !$this->sandbox->allow_references){
                    $this->sandbox->error("Sandboxed code attempted to define function return by reference!", Error::BYREF_ERROR, $node);
                }
            } else if($node instanceof \PHPParser_Node_Expr_Closure){
                if(!$this->sandbox->allow_closures){
                    $this->sandbox->error("Sandboxed code attempted to create a closure!", Error::CLOSURE_ERROR, $node);
                }
            } else if($node instanceof \PHPParser_Node_Stmt_Class){
                if(!$this->sandbox->allow_classes){
                    $this->sandbox->error("Sandboxed code attempted to define class!", Error::DEFINE_CLASS_ERROR, $node);
                }
                if(!$this->sandbox->check_keyword('class')){
                    $this->sandbox->error("Keyword failed custom validation!", Error::VALID_KEYWORD_ERROR, $node, 'class');
                }
                if(!$node->name){
                    $this->sandbox->error("Sandboxed code attempted to define unnamed class!", Error::DEFINE_CLASS_ERROR, $node, '');
                }
                if(!$this->sandbox->check_class($node->name)){
                    $this->sandbox->error("Class failed custom validation!", Error::VALID_CLASS_ERROR, $node, $node->name);
                }
                if($node->extends instanceof \PHPParser_Node_Name){
                    if(!$this->sandbox->check_keyword('extends')){
                        $this->sandbox->error("Keyword failed custom validation!", Error::VALID_KEYWORD_ERROR, $node, 'extends');
                    }
                    if(!$node->extends->toString()){
                        $this->sandbox->error("Sandboxed code attempted to extend unnamed class!", Error::DEFINE_CLASS_ERROR, $node, '');
                    }
                    if(!$this->sandbox->check_class($node->extends->toString(), true)){
                        $this->sandbox->error("Class extension failed custom validation!", Error::VALID_CLASS_ERROR, $node, $node->extends->toString());
                    }
                }
                if(is_array($node->implements)){
                    if(!$this->sandbox->check_keyword('implements')){
                        $this->sandbox->error("Keyword failed custom validation!", Error::VALID_KEYWORD_ERROR, $node, 'implements');
                    }
                    foreach($node->implements as $implement){
                        /**
                         * @var \PHPParser_Node_Name   $implement
                         */
                        if(!$implement->toString()){
                            $this->sandbox->error("Sandboxed code attempted to implement unnamed interface!", Error::DEFINE_INTERFACE_ERROR, $node, '');
                        }
                        if(!$this->sandbox->check_interface($implement->toString())){
                            $this->sandbox->error("Interface failed custom validation!", Error::VALID_INTERFACE_ERROR, $node, $implement->toString());
                        }
                    }
                }
            } else if($node instanceof \PHPParser_Node_Stmt_Interface){
                if(!$this->sandbox->allow_interfaces){
                    $this->sandbox->error("Sandboxed code attempted to define interface!", Error::DEFINE_INTERFACE_ERROR, $node);
                }
                if(!$this->sandbox->check_keyword('interface')){
                    $this->sandbox->error("Keyword failed custom validation!", Error::VALID_KEYWORD_ERROR, $node, 'interface');
                }
                if(!$node->name){
                    $this->sandbox->error("Sandboxed code attempted to define unnamed interface!", Error::DEFINE_INTERFACE_ERROR, $node, '');
                }
                if(!$this->sandbox->check_interface($node->name)){
                    $this->sandbox->error("Interface failed custom validation!", Error::VALID_INTERFACE_ERROR, $node, $node->name);
                }
            } else if($node instanceof \PHPParser_Node_Stmt_Trait){
                if(!$this->sandbox->allow_traits){
                    $this->sandbox->error("Sandboxed code attempted to define trait!", Error::DEFINE_TRAIT_ERROR, $node);
                }
                if(!$this->sandbox->check_keyword('trait')){
                    $this->sandbox->error("Keyword failed custom validation!", Error::VALID_KEYWORD_ERROR, $node, 'trait');
                }
                if(!$node->name){
                    $this->sandbox->error("Sandboxed code attempted to define unnamed trait!", Error::DEFINE_TRAIT_ERROR, $node, '');
                }
                if(!$this->sandbox->check_trait($node->name)){
                    $this->sandbox->error("Trait failed custom validation!", Error::VALID_TRAIT_ERROR, $node, $node->name);
                }
            } else if($node instanceof \PHPParser_Node_Stmt_TraitUse){
                if(!$this->sandbox->check_keyword('use')){
                    $this->sandbox->error("Keyword failed custom validation!", Error::VALID_KEYWORD_ERROR, $node, 'use');
                }
                if(is_array($node->traits)){
                    foreach($node->traits as $trait){
                        /**
                         * @var \PHPParser_Node_Name   $trait
                         */
                        if(!$trait->toString()){
                            $this->sandbox->error("Sandboxed code attempted to use unnamed trait!", Error::DEFINE_TRAIT_ERROR, $node, '');
                        }
                        if(!$this->sandbox->check_trait($trait->toString())){
                            $this->sandbox->error("Trait failed custom validation!", Error::VALID_TRAIT_ERROR, $node, $trait->toString());
                        }
                    }
                }
            } else if($node instanceof \PHPParser_Node_Expr_Yield){
                if(!$this->sandbox->allow_generators){
                    $this->sandbox->error("Sandboxed code attempted to create a generator!", Error::GENERATOR_ERROR, $node);
                }
                if(!$this->sandbox->check_keyword('yield')){
                    $this->sandbox->error("Keyword failed custom validation!", Error::VALID_KEYWORD_ERROR, $node, 'yield');
                }
            } else if($node instanceof \PHPParser_Node_Stmt_Global){
                if(!$this->sandbox->allow_globals){
                    $this->sandbox->error("Sandboxed code attempted to use global keyword!", Error::GLOBALS_ERROR, $node);
                }
                if(!$this->sandbox->check_keyword('global')){
                    $this->sandbox->error("Keyword failed custom validation!", Error::VALID_KEYWORD_ERROR, $node, 'global');
                }
                foreach($node->vars as $var){
                    /**
                     * @var \PHPParser_Node_Expr_Variable    $var
                     */
                    if($var instanceof \PHPParser_Node_Expr_Variable){
                        if(!$this->sandbox->check_global($var->name)){
                            $this->sandbox->error("Global failed custom validation!", Error::VALID_GLOBAL_ERROR, $node, $var->name);
                        }
                    } else {
                        $this->sandbox->error("Sandboxed code attempted to pass non-variable to global keyword!", Error::DEFINE_GLOBAL_ERROR, $node);
                    }
                }
            } else if($node instanceof \PHPParser_Node_Expr_Variable){
                if(!is_string($node->name)){
                    $this->sandbox->error("Sandboxed code attempted dynamically-named variable call!", Error::DYNAMIC_VAR_ERROR, $node);
                }
                if($node->name == $this->sandbox->name){
                    $this->sandbox->error("Sandboxed code attempted to access the PHPSandbox instance!", Error::SANDBOX_ACCESS_ERROR, $node);
                }
                if(in_array($node->name, PHPSandbox::$superglobals)){
                    if(!$this->sandbox->check_superglobal($node->name)){
                        $this->sandbox->error("Superglobal failed custom validation!", Error::VALID_SUPERGLOBAL_ERROR, $node, $node->name);
                    }
                    if($this->sandbox->overwrite_superglobals){
                        return new \PHPParser_Node_Expr_MethodCall(new \PHPParser_Node_Expr_StaticCall(new \PHPParser_Node_Name_FullyQualified("PHPSandbox\\PHPSandbox"), 'getSandbox', array(new \PHPParser_Node_Scalar_String($this->sandbox->name))), '_get_superglobal', array(new \PHPParser_Node_Arg(new \PHPParser_Node_Scalar_String($node->name))), $node->getAttributes());
                    }
                } else {
                    if(!$this->sandbox->check_var($node->name)){
                        $this->sandbox->error("Variable failed custom validation!", Error::VALID_VAR_ERROR, $node, $node->name);
                    }
                }
            } else if($node instanceof \PHPParser_Node_Stmt_StaticVar){
                if(!$this->sandbox->allow_static_variables){
                    $this->sandbox->error("Sandboxed code attempted to create static variable!", Error::STATIC_VAR_ERROR, $node);
                }
                if(!is_string($node->name)){
                    $this->sandbox->error("Sandboxed code attempted dynamically-named static variable call!", Error::DYNAMIC_STATIC_VAR_ERROR, $node);
                }
                if(!$this->sandbox->check_var($node->name)){
                    $this->sandbox->error("Variable failed custom validation!", Error::VALID_VAR_ERROR, $node, $node->name);
                }
                if($node->default instanceof \PHPParser_Node_Expr_New){
                    $node->default = $node->default->args[0];
                }
            } else if($node instanceof \PHPParser_Node_Stmt_Const){
                $this->sandbox->error("Sandboxed code cannot use const keyword in the global scope!", Error::GLOBAL_CONST_ERROR, $node);
            } else if($node instanceof \PHPParser_Node_Expr_ConstFetch){
                if(!$node->name instanceof \PHPParser_Node_Name){
                    $this->sandbox->error("Sandboxed code attempted dynamically-named constant call!", Error::DYNAMIC_CONST_ERROR, $node);
                }
                if(!$this->sandbox->check_const($node->name->toString())){
                    $this->sandbox->error("Constant failed custom validation!", Error::VALID_CONST_ERROR, $node, $node->name->toString());
                }
            } else if($node instanceof \PHPParser_Node_Expr_ClassConstFetch || $node instanceof \PHPParser_Node_Expr_StaticCall || $node instanceof \PHPParser_Node_Expr_StaticPropertyFetch){
                $class = $node->class;
                if(!$class instanceof \PHPParser_Node_Name){
                    $this->sandbox->error("Sandboxed code attempted dynamically-named class call!", Error::DYNAMIC_CLASS_ERROR, $node);
                }
                if($this->sandbox->is_defined_class($class)){
                    $node->class = new \PHPParser_Node_Name($this->sandbox->get_defined_class($class));
                }
                /**
                 * @var \PHPParser_Node_Name    $class
                 */
                if(!$this->sandbox->check_class($class->toString())){
                    $this->sandbox->error("Class constant failed custom validation!", Error::VALID_CLASS_ERROR, $node, $class->toString());
                }
                return $node;
            } else if($node instanceof \PHPParser_Node_Expr_New){
                if(!$this->sandbox->allow_objects){
                    $this->sandbox->error("Sandboxed code attempted to create object!", Error::CREATE_OBJECT_ERROR, $node);
                }
                if(!$this->sandbox->check_keyword('new')){
                    $this->sandbox->error("Keyword failed custom validation!", Error::VALID_KEYWORD_ERROR, $node, 'new');
                }
                if(!$node->class instanceof \PHPParser_Node_Name){
                    $this->sandbox->error("Sandboxed code attempted dynamically-named class call!", Error::DYNAMIC_CLASS_ERROR, $node);
                }
                $class = $node->class->toString();
                if($this->sandbox->is_defined_class($class)){
                    $node->class = new \PHPParser_Node_Name($this->sandbox->get_defined_class($class));
                }
                $this->sandbox->check_type($class);
                return $node;
            } else if($node instanceof \PHPParser_Node_Expr_ErrorSuppress){
                if(!$this->sandbox->allow_error_suppressing){
                    $this->sandbox->error("Sandboxed code attempted to suppress error!", Error::ERROR_SUPPRESS_ERROR, $node);
                }
            } else if($node instanceof \PHPParser_Node_Expr_AssignRef){
                if(!$this->sandbox->allow_references){
                    $this->sandbox->error("Sandboxed code attempted to assign by reference!", Error::BYREF_ERROR, $node);
                }
            } else if($node instanceof \PHPParser_Node_Stmt_HaltCompiler){
                if(!$this->sandbox->allow_halting){
                    $this->sandbox->error("Sandboxed code attempted to halt compiler!", Error::HALT_ERROR, $node);
                }
                if(!$this->sandbox->check_keyword('halt')){
                    $this->sandbox->error("Keyword failed custom validation!", Error::VALID_KEYWORD_ERROR, $node, 'halt');
                }
            } else if($node instanceof \PHPParser_Node_Stmt_Namespace){
                if(!$this->sandbox->allow_namespaces){
                    $this->sandbox->error("Sandboxed code attempted to define namespace!", Error::DEFINE_NAMESPACE_ERROR, $node);
                }
                if(!$this->sandbox->check_keyword('namespace')){
                    $this->sandbox->error("Keyword failed custom validation!", Error::VALID_KEYWORD_ERROR, $node, 'namespace');
                }
                if($node->name instanceof \PHPParser_Node_Name){
                    $namespace = $node->name->toString();
                    $this->sandbox->check_namespace($namespace);
                    if(!$this->sandbox->is_defined_namespace($namespace)){
                        $this->sandbox->define_namespace($namespace);
                    }
                } else {
                    $this->sandbox->error("Sandboxed code attempted use invalid namespace!", Error::DEFINE_NAMESPACE_ERROR, $node);
                }
                return $node->stmts;
            } else if($node instanceof \PHPParser_Node_Stmt_Use){
                if(!$this->sandbox->allow_aliases){
                    $this->sandbox->error("Sandboxed code attempted to use namespace and/or alias!", Error::DEFINE_ALIAS_ERROR, $node);
                }
                if(!$this->sandbox->check_keyword('use')){
                    $this->sandbox->error("Keyword failed custom validation!", Error::VALID_KEYWORD_ERROR, $node, 'use');
                }
                foreach($node->uses as $use){
                    /**
                     * @var \PHPParser_Node_Stmt_UseUse    $use
                     */
                    if($use instanceof \PHPParser_Node_Stmt_UseUse && $use->name instanceof \PHPParser_Node_Name && (is_string($use->alias) || is_null($use->alias))){
                        $this->sandbox->check_alias($use->name->toString());
                        if($use->alias){
                            if(!$this->sandbox->check_keyword('as')){
                                $this->sandbox->error("Keyword failed custom validation!", Error::VALID_KEYWORD_ERROR, $node, 'as');
                            }
                        }
                        $this->sandbox->define_alias($use->name->toString(), $use->alias);
                    } else {
                        $this->sandbox->error("Sandboxed code attempted use invalid namespace or alias!", Error::DEFINE_ALIAS_ERROR, $node);
                    }
                }
                return false;
            } else if($node instanceof \PHPParser_Node_Expr_ShellExec){
                if($this->sandbox->is_defined_func('shell_exec')){
                    $args = array(
                        new \PHPParser_Node_Arg(new \PHPParser_Node_Scalar_String('shell_exec')),
                        new \PHPParser_Node_Arg(new \PHPParser_Node_Scalar_String(implode('', $node->parts)))
                    );
                    return new \PHPParser_Node_Expr_MethodCall(new \PHPParser_Node_Expr_StaticCall(new \PHPParser_Node_Name_FullyQualified("PHPSandbox\\PHPSandbox"), 'getSandbox', array(new \PHPParser_Node_Scalar_String($this->sandbox->name))), 'call_func', $args, $node->getAttributes());
                }
                if($this->sandbox->has_whitelist_funcs()){
                    if(!$this->sandbox->is_whitelisted_func('shell_exec')){
                        $this->sandbox->error("Sandboxed code attempted to use shell execution backticks when the shell_exec function is not whitelisted!", Error::BACKTICKS_ERROR, $node);
                    }
                } else if($this->sandbox->has_blacklist_funcs() && $this->sandbox->is_blacklisted_func('shell_exec')){
                    $this->sandbox->error("Sandboxed code attempted to use shell execution backticks when the shell_exec function is blacklisted!", Error::BACKTICKS_ERROR, $node);
                }
                if(!$this->sandbox->allow_backticks){
                    $this->sandbox->error("Sandboxed code attempted to use shell execution backticks!", Error::BACKTICKS_ERROR, $node);
                }
            } else if($name = $this->is_magic_const($node)){
                if(!$this->sandbox->check_magic_const($name)){
                    $this->sandbox->error("Magic constant failed custom validation!", Error::VALID_MAGIC_CONST_ERROR, $node, $name);
                }
                if($this->sandbox->is_defined_magic_const($name)){
                    return new \PHPParser_Node_Expr_MethodCall(new \PHPParser_Node_Expr_StaticCall(new \PHPParser_Node_Name_FullyQualified("PHPSandbox\\PHPSandbox"), 'getSandbox', array(new \PHPParser_Node_Scalar_String($this->sandbox->name))), '_get_magic_const', array(new \PHPParser_Node_Arg(new \PHPParser_Node_Scalar_String($name))), $node->getAttributes());
                }
            } else if($name = $this->is_keyword($node)){
                if(!$this->sandbox->check_keyword($name)){
                    $this->sandbox->error("Keyword failed custom validation!", Error::VALID_KEYWORD_ERROR, $node, $name);
                }
            } else if($name = $this->is_operator($node)){
                if(!$this->sandbox->check_operator($name)){
                    $this->sandbox->error("Operator failed custom validation!", Error::VALID_OPERATOR_ERROR, $node, $name);
                }
            } else if($name = $this->is_primitive($node)){
                if(!$this->sandbox->check_primitive($name)){
                    $this->sandbox->error("Primitive failed custom validation!", Error::VALID_PRIMITIVE_ERROR, $node, $name);
                }
                if($node instanceof \PHPParser_Node_Scalar_String){
                    return new \PHPParser_Node_Expr_New(new \PHPParser_Node_Name_FullyQualified(array('PHPSandbox\\SandboxedString')), array(new \PHPParser_Node_Arg($node), new \PHPParser_Node_Arg(new \PHPParser_Node_Expr_StaticCall(new \PHPParser_Node_Name_FullyQualified("PHPSandbox\\PHPSandbox"), 'getSandbox', array(new \PHPParser_Node_Scalar_String($this->sandbox->name))))));
                }
            }
            return null;
        }
        /** Test the current PHPParser_Node node to see if it is a magic constant, and return the name if it is and null if it is not
         *
         * @param   \PHPParser_Node   $node          The sandboxed $node to test
         *
         * @return  string|null       Return string name of node, or null if it is not a magic constant
         */
        protected function is_magic_const(\PHPParser_Node $node){
            switch($node->getType()){
                case 'Scalar_ClassConst':
                    return '__CLASS__';
                case 'Scalar_DirConst':
                    return '__DIR__';
                case 'Scalar_FileConst':
                    return '__FILE__';
                case 'Scalar_FuncConst':
                    return '__FUNCTION__';
                case 'Scalar_LineConst':
                    return '__LINE__';
                case 'Scalar_MethodConst':
                    return '__METHOD__';
                case 'Scalar_NSConst':
                    return '__NAMESPACE__';
                case 'Scalar_TraitConst':
                    return '__TRAIT__';
            }
            return null;
        }
        /** Test the current PHPParser_Node node to see if it is a keyword, and return the name if it is and null if it is not
         *
         * @param   \PHPParser_Node   $node          The sandboxed $node to test
         *
         * @return  string|null       Return string name of node, or null if it is not a keyword
         */
        protected function is_keyword(\PHPParser_Node $node){
            switch($node->getType()){
                case 'Expr_Eval':
                    return 'eval';
                case 'Expr_Exit':
                    return 'exit';
                case 'Expr_Include':
                    return 'include';
                case 'Stmt_Echo':
                case 'Expr_Print':  //for our purposes print is treated as functionally equivalent to echo
                    return 'echo';
                case 'Expr_Clone':
                    return 'clone';
                case 'Expr_Empty':
                    return 'empty';
                case 'Expr_Yield':
                    return 'yield';
                case 'Stmt_Goto':
                case 'Stmt_Label':  //no point in using labels without goto
                    return 'goto';
                case 'Stmt_If':
                case 'Stmt_Else':    //no point in using ifs without else
                case 'Stmt_ElseIf':  //no point in using ifs without elseif
                    return 'if';
                case 'Stmt_Break':
                    return 'break';
                case 'Stmt_Switch':
                case 'Stmt_Case':    //no point in using cases without switch
                    return 'switch';
                case 'Stmt_Try':
                case 'Stmt_Catch':    //no point in using catch without try
                case 'Stmt_TryCatch': //no point in using try, catch or finally without try
                    return 'try';
                case 'Stmt_Throw':
                    return 'throw';
                case 'Stmt_Unset':
                    return 'unset';
                case 'Stmt_Return':
                    return 'return';
                case 'Stmt_Static':
                    return 'static';
                case 'Stmt_While':
                case 'Stmt_Do':       //no point in using do without while
                    return 'while';
                case 'Stmt_Declare':
                case 'Stmt_DeclareDeclare': //no point in using declare key=>value without declare
                    return 'declare';
                case 'Stmt_For':
                case 'Stmt_Foreach':  //no point in using foreach without for
                    return 'for';
                case 'Expr_Instanceof':
                    return 'instanceof';
                case 'Expr_Isset':
                    return 'isset';
                case 'Expr_List':
                    return 'list';
            }
            return null;
        }
        /** Test the current PHPParser_Node node to see if it is an operator, and return the name if it is and null if it is not
         *
         * @param   \PHPParser_Node   $node          The sandboxed $node to test
         *
         * @return  string|null       Return string name of node, or null if it is not an operator
         */
        protected function is_operator(\PHPParser_Node $node){
            switch($node->getType()){
                case 'Expr_Assign':
                    return '=';
                case 'Expr_AssignBitwiseAnd':
                    return '&=';
                case 'Expr_AssignBitwiseOr':
                    return '|=';
                case 'Expr_AssignBitwiseXor':
                    return '^=';
                case 'Expr_AssignConcat':
                    return '.=';
                case 'Expr_AssignDiv':
                    return '/=';
                case 'Expr_AssignMinus':
                    return '-=';
                case 'Expr_AssignMod':
                    return '%=';
                case 'Expr_AssignMul':
                    return '*=';
                case 'Expr_AssignPlus':
                    return '+=';
                case 'Expr_AssignRef':
                    return '=&';
                case 'Expr_AssignShiftLeft':
                    return '<<=';
                case 'Expr_AssignShiftRight':
                    return '>>=';
                case 'Expr_BitwiseAnd':
                    return '&';
                case 'Expr_BitwiseNot':
                    return '~';
                case 'Expr_BitwiseOr':
                    return '|';
                case 'Expr_BitwiseXor':
                    return '^';
                case 'Expr_BooleanAnd':
                    return '&&';
                case 'Expr_BooleanNot':
                    return '!';
                case 'Expr_BooleanOr':
                    return '||';
                case 'Expr_Concat':
                    return '.';
                case 'Expr_Div':
                    return '/';
                case 'Expr_Equal':
                    return '==';
                case 'Expr_Greater':
                    return '>';
                case 'Expr_GreaterOrEqual':
                    return '>=';
                case 'Expr_Identical':
                    return '===';
                case 'Expr_LogicalAnd':
                    return 'and';
                case 'Expr_LogicalOr':
                    return 'or';
                case 'Expr_LogicalXor':
                    return 'xor';
                case 'Expr_Minus':
                    return '-';
                case 'Expr_Mod':
                    return '%';
                case 'Expr_Mul':
                    return '*';
                case 'Expr_NotEqual':
                    return '!=';
                case 'Expr_NotIdentical':
                    return '!==';
                case 'Expr_Plus':
                    return '+';
                case 'Expr_PostDec':
                    return 'n--';
                case 'Expr_PostInc':
                    return 'n++';
                case 'Expr_PreDec':
                    return '--n';
                case 'Expr_PreInc':
                    return '++n';
                case 'Expr_ShiftLeft':
                    return '<<';
                case 'Expr_ShiftRight':
                    return '>>';
                case 'Expr_Smaller':
                    return '<';
                case 'Expr_SmallerOrEqual':
                    return '<=';
                case 'Expr_Ternary':
                    return '?';
                case 'Expr_UnaryMinus':
                    return '-n';
                case 'Expr_UnaryPlus':
                    return '+n';
            }
            return null;
        }
        /** Test the current PHPParser_Node node to see if it is a primitive, and return the name if it is and null if it is not
         *
         * @param   \PHPParser_Node   $node          The sandboxed $node to test
         *
         * @throws  Error             Throws exception if $node attempts to cast when $allow_casting is false in the PHPSandbox configuration
         *
         * @return  string|null       Return string name of node, or null if it is not a primitive
         */
        protected function is_primitive(\PHPParser_Node $node){
            switch($node->getType()){
                case 'Expr_Cast_Array':
                case 'Expr_Cast_Bool':
                case 'Expr_Cast_Double':
                case 'Expr_Cast_Int':
                case 'Expr_Cast_String':
                case 'Expr_Cast_Object':
                case 'Expr_Cast_Unset':
                    if(!$this->sandbox->allow_casting){
                        $this->sandbox->error("Sandboxed code attempted to cast!", Error::CAST_ERROR, $node);
                    }
                    break;
            }
            switch($node->getType()){
                case 'Expr_Cast_Array':
                case 'Expr_Array':
                    return 'array';
                case 'Expr_Cast_Bool': //booleans are treated as constants otherwise. . .
                    return 'bool';
                case 'Expr_Cast_String':
                case 'Scalar_String':
                case 'Scalar_Encapsed':
                    return 'string';
                case 'Expr_Cast_Double':
                case 'Scalar_DNumber':
                    return 'float';
                case 'Expr_Cast_Int':
                case 'Scalar_LNumber':
                    return 'int';
                case 'Expr_Cast_Object':
                    return 'object';
            }
            return null;
        }
    }