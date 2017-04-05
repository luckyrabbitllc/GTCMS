<?php

namespace App\Classes;

use App\Models\BaseModel;

class AdminHelper {

	public static function modelConfigs() {
		$modelsArray = config('gtcmsmodels.models');
		$models = array();
		foreach ($modelsArray as $modelName => $model) {
			$models[$modelName] = self::arrayToObject($model);
		}
		return $models;
	}

	public static function modelExists($modelProperty, $property = 'name') {
		if ($property == 'name') {
			$modelConfigs = config('gtcmsmodels.models');
			if (array_key_exists($modelProperty, $modelConfigs)) {
				return self::arrayToObject($modelConfigs[$modelProperty]);
			}
		} else {
			foreach (self::modelConfigs() as $modelConfig) {
				if ($modelConfig->$property == $modelProperty) return $modelConfig;
			}
		}
		return false;
	}

	public static function arrayToObject($arrayOrValue, $makeModelConfig = true) {
		if ($makeModelConfig) {
			$object = new ModelConfig();
		} else {
			$object = new BaseClass();
		}
		if (is_array($arrayOrValue)) {
			foreach ($arrayOrValue as $key => $value) {
				if (is_array($value)) {
					$object->$key = self::arrayToObject($value, false);
				} else {
					$object->$key = $value;
				}
			}
			return $object;
		} else {
			// It's an actual value
			return $arrayOrValue;
		}
	}

	public static function objectToArray($object) {
		if (is_object($object)) {
			$object = json_decode(json_encode($object), true);
		}

		return $object;
	}

	public static function validationRules($modelConfig, $object = NULL, $quickEdit = false) {
		$rules = array();
		/** @var ModelConfig $modelConfig */
		$formFields = $quickEdit ? $modelConfig->getQuickEditFields('all') : $modelConfig->formFields;
		if ($formFields) {

			$user = \Auth::user();

			foreach ($formFields as $field) {
				if ($field->rules && !in_array($field->type, array('file', 'image'))) {
					$editRules = array();
					$addRules = ModelConfig::rulesToArray($field->rules);
					if ($field->editRules) {
						$editRules = ModelConfig::rulesToArray($field->editRules);
					}

					// If object is set and complete, it means it's being Edited
					if ($object && $object->id) {
						if (self::fieldShouldBeUnsetFromInput($modelConfig, $field, 'edit', $user)) {
							$fieldRules = [];
						} else {
							$fieldRules = $field->editRules ? $editRules : $addRules;
							foreach ($fieldRules as &$rule) {
								$rule = str_replace('{ignoreId}', $object->id, $rule);
								$rule = str_replace('{addRequired}', '', $rule);
							}
						}
					} else {
						// New object is being created
						if (self::fieldShouldBeUnsetFromInput($modelConfig, $field, 'add', $user)) {
							$fieldRules = [];
						} else {
							$fieldRules = $addRules;
							foreach ($fieldRules as &$rule) {
								$rule = str_replace('{addRequired}', 'required', $rule);
							}
						}
					}
					if (config('gtcms.premium') && $field->langDependent) {
						foreach (config('gtcmslang.languages') as $lang) {
							$property = $field->property."_".$lang;
							$rules[$property] = $fieldRules;
						}
					} else {
						$rules[$field->property] = $fieldRules;
					}
				}
			}
		}

		return $rules;
	}

	public static function getValidatorAttributes() {
		$validatorAttributes = array();
		foreach (self::modelConfigs() as $modelConfig) {
			foreach ($modelConfig->formFields as $field) {
				if (config('gtcms.premium') && $field->langDependent) {
					foreach (config('gtcmslang.languages') as $lang) {
						$property = $field->property . "_" . $lang;
						$validatorAttributes[$property] = $field->label;
					}
				} else {
					$validatorAttributes[$field->property] = $field->label;
				}
			}
		}
		return $validatorAttributes;
	}

	public static function modelConfigHasImage($modelConfig) {
		$imageFields = array();
		foreach ($modelConfig->formFields as $field) {
			if ($field->type == 'image') {
				$imageFields[] = $field;
			}
		}
		if (empty($imageFields)) {
			return false;
		} else {
			return $imageFields;
		}
	}

	public static function modelConfigHasFile($modelConfig) {
		$fileFields = array();
		foreach ($modelConfig->formFields as $field) {
			if ($field->type == 'file') {
				$fileFields[] = $field;
			}
		}
		if (empty($fileFields)) {
			return false;
		} else {
			return $fileFields;
		}
	}

	public static function getImageFieldRequirements($modelConfig, $fieldProperty) {
		$size = false;
		if ($modelConfig->standalone === false && $parentIdProperty = self::firstParamIsParent($modelConfig)) {
			$parentModelConfig = AdminHelper::modelExists($parentIdProperty, 'id');
			if ($parentModelConfig && $parentModelConfig->imageSizes) {
				if ($parentModelConfig->keyBasedSizes) {
					$objectId = AdminHelper::firstParamIsParent($parentModelConfig, true);
					/** @var BaseModel $modelName */
					/** @var ModelConfig $parentModelConfig */
					$modelName = $parentModelConfig->myFullEntityName();
					$object = $modelName::find($objectId);
					$modelKey = $object->model_key;
					$imageSizes = $parentModelConfig->imageSizes->$modelKey;
				} else {
					$imageSizes = $parentModelConfig->imageSizes;
				}

				if ($imageSizes) {
					foreach ($imageSizes as $size) {
						$size = AdminHelper::objectToArray($size);
						break;
					}
				}
			}
		}

		if (!$size) {
			foreach ($modelConfig->formFields as $field) {
				if ($field->property == $fieldProperty) {
					foreach ($field->sizes as $size) {
						$size = AdminHelper::objectToArray($size);
						break;
					}
				}
			}
		}

		if ($size) {
			$data = array(
				'minWidth' => $size[0],
				'minHeight' => $size[1],
				'transformMethod' => $size[2],
				'folder' => $size[3],
				'quality' => $size[4]
			);
			return $data;
		}

		return false;
	}

	public static function getOrderParams($modelConfig) {
		if (\Request::has('orderBy')) {
			$orderBy = \Request::get('orderBy');
			$acceptable = false;
			foreach ($modelConfig->formFields as $field) {
				if ($field->property == $orderBy && $field->order) {
					$acceptable = true;
					if (config('gtcms.premium') && $field->langDependent) {
						$orderBy = $orderBy . "_" . (\App::getLocale());
					}
					break;
				}
			}
			if (!$acceptable) $orderBy = $modelConfig->orderBy;
		} else {
			$orderBy = $modelConfig->orderBy;
		}

		if (\Request::has('direction')) {
			$direction = \Request::get('direction');
			if (!in_array($direction, array('asc', 'desc'))) {
				$direction = $modelConfig->direction;
			}
		} else {
			$direction = $modelConfig->direction;
		}

		return array('orderBy' => $orderBy, 'direction' => $direction);
	}

	public static function getSearchData(ModelConfig $modelConfig, $searchFieldValue = false) {

		if (\Request::isMethod('get')) {
			$properties = array();
			$searchPropertiesData = $modelConfig->getSearchPropertiesData();
			$searchProperties = $searchPropertiesData['properties'];
			$searchConfig = $searchPropertiesData['searchConfig'];
			$fieldsWithLabels = $modelConfig->getFieldsWithLabels(true);
			$propertiesTables = $modelConfig->getPropertiesTables();
			$langDependentProperties = $modelConfig->getLangDependentProperties();
			$propertyFieldArray = $modelConfig->getPropertyFieldArray();

			foreach(\Request::all() as $property => $value) {
				if (strpos($property, 'search_') === 0 && $value) {
					$property = explode("search_", $property);
					if (isset($property[1])) {
						$property = $property[1];
						$fieldFrom = $fieldTo = false;
						if (strpos($property, '_fieldFrom') !== false) {
							$trueProperty = explode("_fieldFrom", $property);
							$trueProperty = $trueProperty[0];
							$fieldFrom = true;
						} else if (strpos($property, '_fieldTo') !== false) {
							$trueProperty = explode("_fieldTo", $property);
							$trueProperty = $trueProperty[0];
							$fieldTo = true;
						} else {
							$trueProperty = $property;
						}

						if (in_array($property, $searchProperties)) {
							if ($searchFieldValue) {
								$type = $searchConfig[$property]['type'];
								if ($type == 'standard') {
									$value = $modelConfig->getPropertyValue($trueProperty, $value);
								} else if ($type == 'exception') {
									//custom code here
								}
							}

							$properties[] = array(
								'property' => $property,
								'trueProperty' => $trueProperty,
								'dbProperty' => $propertiesTables[$trueProperty].".".$trueProperty,
								'langDependent' => config('gtcms.premium') && in_array($trueProperty, $langDependentProperties) ? true : false,
								'label' => isset($fieldsWithLabels[$property]) ? $fieldsWithLabels[$property] : 'Undefined',
								'value' => $value,
								'searchConfig' => $searchConfig[$property],
								'fieldFrom' => $fieldFrom,
								'fieldTo' => $fieldTo,
								'type' => isset($propertyFieldArray[$trueProperty]) ? $propertyFieldArray[$trueProperty]->type : null
							);
						}
					}
				}
			}

			return $properties;
		}

		return array();
	}

	public static function standaloneCheck($modelConfig, $action, &$input, $object = NULL) {
		if ($modelConfig->standalone === false) {

			if (empty($_GET)) Throw new \Exception("Parent not defined.");

			$requiredParents = AdminHelper::objectToArray($modelConfig->requiredParents);
			$parentProperty = "";

			if ($action == 'add') {

				// Check only first get parameter, because that has to be the parent
				$requiredParent = $parentId =  false;
				foreach ($_GET as $requiredParent => $parentId) {
					if (!in_array($requiredParent, $requiredParents) || !is_numeric($parentId)) Throw new \Exception("Wrong parent or parent ID.");
					$parentProperty = $requiredParent;
					break;
				}

				$parentModelConfig = self::modelExists($requiredParent, 'id');
				/** @var BaseModel $fullParent */
				$fullParent = $parentModelConfig->myFullEntityName();
				$parentObject = $fullParent::find($parentId);
				if (!$parentObject) {
					Throw new \Exception("Wrong parent ID.");
				}

				// Also make sure all other parents are removed from input
				$counter = -1;
				foreach ($_GET as $key => $value) {
					$counter++;
					if ($counter == 0) continue;
					if (in_array($key, $requiredParents) && isset($input[$key])) unset($input[$key]);
				}

			} else if ($action == 'edit') {

				// Get original parent
				$originalParent = $parentIdProperty = false;
				foreach ($requiredParents as $parentId) {
					if ($object->$parentId) {
						$originalParent = $object->$parentId;
						$parentIdProperty = $parentId;
						break;
					}
				}

				if (!$originalParent) Throw new \Exception("Couldn't find original parent.");

				// Check only first get parameter, because that has to be the parent
				foreach ($_GET as $requiredParent => $parentId) {
					if ($requiredParent != $parentIdProperty || $parentId != $originalParent) Throw new \Exception("Wrong parent or parent ID.");
					$parentProperty = $requiredParent;
					break;
				}

				// Also make sure all other parents are removed from input
				$counter = -1;
				foreach ($_GET as $key => $value) {
					$counter++;
					if ($counter == 0) continue;
					if (in_array($key, $requiredParents) && isset($input[$key])) unset($input[$key]);
				}

			} else throw new \Exception("Action incorrect.");

			return $parentProperty;
		}

		return false;
	}


	public static function input($modelConfig, $action) {
		$input = \Request::all();
		$user = \Auth::user();

		if (is_array($input) && !empty($input)) {
			$formFields = array();
			$languages = config('gtcmslang.languages');

			foreach ($modelConfig->formFields as $field) {
				if (config('gtcms.premium') && config('gtcmslang.siteIsMultilingual') && $field->langDependent) {
					foreach ($languages as $language) {
						$formFields[$field->property . "_" . $language] = $field;
					}
				} else {
					$formFields[$field->property] = $field;
				}
			}

			foreach ($input as $property => &$value) {

				//set parent IDs to NULL if no value was selected from dropdown
				foreach (AdminHelper::modelConfigs() as $currentModelConfig) {
					if (!is_array($property) && $property == $currentModelConfig->id && !$value) {
						$value = NULL;
					}
				}

				if ($modelConfig) {
					if (isset($formFields[$property])) {
						$field = $formFields[$property];

						if (self::fieldShouldBeUnsetFromInput($modelConfig, $field, $action, $user)) {
							unset($input[$property]);
						} else {
							//format DateTime / Date
							if (in_array($field->type, array('date', 'dateTime'))) {
								if (Tools::validateDate($value)) {
									if ($field->type == "date") {
										$value = date("Y-m-d", strtotime($value));
									} else if ($field->type == "dateTime") {
										$value = date("Y-m-d H:i:s", strtotime($value));
									} else {
										$value = "";
									}
								} else {
									$value = "";
								}
							}

							if (!is_array($value) && !is_null($value)) {
								$value = trim($value);
							}

							//set null when empty
							if ($field->setNullWhenEmpty && !$value && $value !== 0 && $value !== "0") {
								$value = null;
							}
						}
					} else {
						// unset property if it's not defined in ModelConfig's formFields, i.e. the
						// property is for some reason listed in Model's $fillables but is not meant
						// to be edited through CMS.
						//
						// Make sure parent properties aren't unset.

						$unset = true;

						// make sure parent isn't removed from input (tree structure)
						if ($modelConfig->parent && $modelConfig->parent->property == $property) {
							$unset = false;
						}

						// make sure parent isn't removed from input (table structure)
						if ($property == self::firstParamIsParent($modelConfig)) {
							$unset = false;
						}

						if ($unset) {
							unset($input[$property]);
						}
					}
				}
			}
		}

		if ($modelConfig->name == "User" && isset($input['is_superadmin']) && !\Auth::user()->is_superadmin) {
			$input['is_superadmin'] = 0;
		}

		return $input;
	}

	public static function fieldShouldBeUnsetFromInput($modelConfig, $field, $action, $user = null) {

		// unset property if it's only meant to be viewed
		if ($field->viewField) {
			return true;

			// unset property if it's supposed to be hidden for current action
		} else if ($field->hidden && $field->hidden->$action) {
			return true;
		}

		if (!$user) {
			$user = \Auth::user();
		}

		// unset property if it's restricted to superadmin and user isn't superadmin
		if ($field->restrictedToSuperadmin && (!$user || !$user->is_superadmin)) {
			return true;
		}

		$userRole = $user && $user->role ? $user->role : "gtcms_undefined_user_role";

		// unset property if user isn't allowed to edit it
		if ($field->restrictedAccess && !$field->restrictedAccess->$userRole) {
			return true;

			// unset property if it's only meant to be viewed by current user based on their role
		} else if ($field->viewFieldForRoles && $field->viewFieldForRoles->$userRole) {
			return true;
		}

		return false;
	}

	public static function modelImageMinDimensions() {
		if (!empty($_GET)) {
			$parentProperty = false;
			foreach ($_GET as $requiredParent => $parentId) {
				$parentProperty = $requiredParent;
				break;
			}
			if ($parentModelConfig = AdminHelper::modelExists($parentProperty, 'id')) {
				if ($parentModelConfig->imageSizes) {
					$parentModelConfig = AdminHelper::modelExists($parentProperty, 'id');
					if (config('gtcms.premium') && $parentModelConfig->keyBasedSizes) {
						$sizes = GtcmsPremium::getKeyBasedImageSizes($parentModelConfig);
					} else {
						$sizes = $parentModelConfig->imageSizes;
					}

					if ($sizes) {
						$size = false;
						foreach ($sizes as $size) break;
						$size = AdminHelper::objectToArray($size);
						$minWidth = $size[0];
						$minHeight = $size[1];
						$manipulationType = $size[2];
						return array($minWidth, $minHeight, $manipulationType);
					} else {
						return false;
					}
				} else {
					return false;
				}
			}
			return false;
		} else {
			return false;
		}
	}

	public static function getModelConfigFieldValue($modelConfig, $originalField, BaseModel $object, $currentLanguage = NULL, $returnLabel = false, $export = false) {

		$languages = config('gtcmslang.languages');
		if (is_numeric($currentLanguage)) {
			foreach ($languages as $key => $language) {
				if ($key == $currentLanguage) {
					$lang = $language;
				}
			}
		} else if (!is_null($currentLanguage)) {
			$lang = $languages[$currentLanguage];
		} else {
			$lang = "";
		}

		$field = clone($originalField);

		if (config('gtcms.premium') && $field->langDependent) {
			$field->property .= "_" . $lang;
			$field->label .= " [$lang]";
		}

		if ($returnLabel) {
			return $field->label;
		}

		$value = "";
		$displayProperty = $field->displayProperty ? $field->displayProperty : $field->property;
		if (is_object($displayProperty)) {
			if ($displayProperty->type == 'model') {
				$method = $displayProperty->method;
				$relatedProperty = $displayProperty->property;
				if ($object->$method()->count()) {
					if ($displayProperty->multiple) {
						$relatedModels = $object->$method()->withPivot('position')->orderBy('pivot_position', 'asc')->get();
						$value = "";
						foreach ($relatedModels as $relModel) {
							$value .= ($relModel->$relatedProperty) . ", ";
						}
						$value = rtrim($value, ", ");
					} else {
						$value = $object->$method->$relatedProperty;
					}
				} else {
					$value = " - ";
				}
			} else if ($displayProperty->type == 'accessor') {
				$method = $displayProperty->method;
				if ($method == 'indexDate' && $export) {
					$value = $object->getIndexDateAttribute();
				} else {
					$value = $object->$method;
				}
			} else if ($displayProperty->type == 'image') {
				$method = $displayProperty->method;
				if ($object->$method('name')) {
					if ($displayProperty->display == 'image' && !$export) {
						$value = "<img style='max-width: 300px; max-height: 300px' src='" . $object->$method('url', 'original', $field->property) . "' >";
					} else if ($displayProperty->display == 'name' || $export) {
						$value = $object->$method('name');
					}
				} else {
					$value = "This ".$modelConfig->hrName." has no ".$field->label.".";
				}
			} else if (in_array($displayProperty->type, array('date', 'dateTime'))) {
				$property = $field->property;
				$value = $object->formatDate($object->$property, $displayProperty->dateFormat ? $displayProperty->dateFormat : $property->dateFormat);
			} else if ($displayProperty->type == 'file') {
				$method = $displayProperty->method;
				if ($object->$method('name')) {
					if ($displayProperty->display == 'url' && !$export) {
						$value = "<a href='".$object->$method() . "' target='_blank'>" . $object->$method('name') . "</a>";
					} else if ($displayProperty->display == 'name' || $export) {
						$value = $object->$method('name');
					}
				} else {
					$value = "This " . $modelConfig->hrName . " has no " . $field->label . ".";
				}
			}
		} else if ($field->type == 'checkbox') {
			$value = $object->$displayProperty ? Front::drawCheckboxIcon(true) : Front::drawCheckboxIcon(false);
		} else {
			$value = $object->$displayProperty;
		}

		return $value;
	}

	public static function firstParamIsParent(ModelConfig $modelConfig, $returnParentIdValue = false) {
		if ($modelConfig->getModelParents() || $modelConfig->parent) {
			$parentIdProperties = AdminHelper::objectToArray($modelConfig->getModelParents());
			if (!$parentIdProperties) {
				$parentIdProperties = array($modelConfig->parent->property);
			}
			if (!empty($_GET)) {
				foreach ($_GET as $parentIdProperty => $id) {
					if (in_array($parentIdProperty, $parentIdProperties) && is_numeric($id)) {
						if ($returnParentIdValue) {
							return $id;
						}
						return $parentIdProperty;
					}

					// Break after first iteration because first $_GET must be the parent

					break;
				}
			}
		}
		return false;
	}

	public static function getFieldsByParam($modelConfig, $paramName, $paramValue, $returnFirst = false) {
		$fields = array();
		foreach ($modelConfig->formFields as $field) {
			if (config('gtcms.premium') && $field->langDependent) {
				foreach (config('gtcmslang.languages') as $lang) {
					if (($field->$paramName . "_" . $lang) === $paramValue) {
						if ($returnFirst) {
							return $field;
						}
						$fields[] = $field;
					}
				}

			} else {
				if ($field->$paramName === $paramValue) {
					if ($returnFirst) {
						return $field;
					}
					$fields[] = $field;
				}
			}
		}
		return $fields;
	}

	public static function getValidatorErrors($validator) {
		$messages = $validator->getMessageBag()->toArray();
		$finalMessages = array();
		foreach ($messages as $field => $fieldMessages) {
			foreach ($fieldMessages as $fieldMessage) {
				$finalMessages[] = $fieldMessage;
			}
		}
		return implode(", ", $finalMessages);
	}

	public static function getLangDependentFields($modelConfig) {
		$fields = array();
		foreach ($modelConfig->formFields as $field) {
			if (config('gtcms.premium') && $field->langDependent) {
				$fields[] = $field->property;
			}
		}
		if (config('gtcms.premium') && $modelConfig->generateSlug && $modelConfig->langDependentSlug) {
			$fields[] = "slug";
		}
		return $fields;
	}

	public static function setNavigationSize($size = false) {
		if (in_array($size, array('narrow', 'wide'))) {
			\Session::put('gtcmsNavSize', $size);
		} else {
			\Session::put('gtcmsNavSize', 'wide');
		}
	}

	public static function getNavigationSize() {
		$size = \Session::get('gtcmsNavSize');
		if (in_array($size, array('narrow', 'wide'))) {
			return $size;
		}

		self::setNavigationSize();
		return 'wide';
	}

	public static function handleException(\Exception $e, $message = null, $preventException = false, $messageType = 'exception') {
		$requestIsAjax = \Request::ajax() && \Request::get('getIgnore_isAjax') ? true : false;

		if (config('gtcms.throwExceptions') && !$preventException) {
			if ($requestIsAjax) {
				Dbar::error($e->getMessage());
				Dbar::critical($e);
			}
			throw $e;
		} else {
			if ($requestIsAjax) {
				Dbar::error($e->getMessage());
				Dbar::critical($e);
				$data = array(
					'success' => false,
					$messageType => is_null($message) ? "Error: " . $e->getMessage() : $message
				);
				return \Response::json($data);
			} else {
				$message = is_null($message) ? "Error: " . $e->getMessage() : $message;

				// Sometimes the request can be Ajax request even if
				// it doesn't have getIgnore_isAjax set to true.
				// In this case we don't want to se the exception via Message Manager.
				// Example: Image / File uploading.

				if (!\Request::ajax()) {
					MessageManager::setException($message);
				}

				return \Redirect::to(AdminHelper::getCmsPrefix());
			}
		}
	}

	public static function getCmsPrefix($prependSlash = true, $appendSlash = true, $returnEmptyIfNoPrefix = false) {
		$prefix = config('gtcms.cmsPrefix');

		if ($returnEmptyIfNoPrefix && !$prefix) {
			return "";
		}

		if ($prependSlash) {
			$prefix = "/" . $prefix;
		}
		if ($appendSlash) {
			$prefix .= "/";
		}
		$prefix = str_replace("//", "/", $prefix);

		return $prefix;
	}

	public static function returnDefaultDataSet($testMessage = "Returning default data set", $success = false, $message = "Testing") {

		// This method is used for debugging purposes.
		// By default it's not called anywhere.

		Dbar::error($testMessage);

		$data = [
			'success' => $success,
			'message' => $message
		];

		return \Response::json($data);
	}

}



























