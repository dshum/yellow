<?php namespace LemonTree\Controllers;

use LemonTree\ElementInterface;
use LemonTree\Site;
use LemonTree\Item;
use LemonTree\Element;
use LemonTree\LoggedUser;
use LemonTree\UserActionType;
use LemonTree\Models\UserAction;
use LemonTree\Properties\FileProperty;
use LemonTree\Properties\ImageProperty;
use LemonTree\Properties\OrderProperty;

class EditController extends Controller {

	public function copy($classId)
	{
		$scope = array();

		$currentElement = Element::getByClassId($classId);

		if ( ! $currentElement) {
			$scope['state'] = 'error_element_not_found';
			return \Response::json($scope);
		}

		$loggedUser = LoggedUser::getUser();

		if ( ! $loggedUser->hasViewAccess($currentElement)) {
			$scope['state'] = 'error_element_access_denied';
			return \Response::json($scope);
		}

		$clone = new $currentElement;

		$input = \Input::all();

		$site = \App::make('site');

		$currentItem = $site->getItemByName($currentElement->getClass());

		$propertyList = $currentItem->getPropertyList();

		foreach ($propertyList as $propertyName => $property) {
			if ($property instanceof OrderProperty) {
				$property->setElement($clone)->set();
				continue;
			}

			if (
				$property->getHidden()
				|| $property->getReadonly()
			) continue;

			if (
				(
					$property instanceof FileProperty
					|| $property instanceof ImageProperty
				)
				&& ! $property->getRequired()
			) continue;

			if (
				$property->isOneToOne()
				&& isset($input[$propertyName])
			) {
				$clone->$propertyName = $input[$propertyName];
			} else {
				$clone->$propertyName = $currentElement->$propertyName;
			}
		}

		try {
			$clone->save();
		} catch (\Exception $e) {
			$scope['state'] = 'error_element_save_failed';
			$scope['error'] = $e->getMessage();
			return \Response::json($scope);
		}

		\Cache::tags($currentElement->getClass())->flush();

		UserAction::log(
			UserActionType::ACTION_TYPE_COPY_ELEMENT_ID,
			$currentElement->getClassId()
			.' -> '
			.$clone->getClassId()
		);

		$scope['clone'] = $clone->getClassId();
		$scope['state'] = 'ok';

		return \Response::json($scope);
	}

	public function move($classId)
	{
		$scope = array();

		$currentElement = Element::getByClassId($classId);

		if ( ! $currentElement) {
			$scope['state'] = 'error_element_not_found';
			return \Response::json($scope);
		}

		$loggedUser = LoggedUser::getUser();

		if ( ! $loggedUser->hasUpdateAccess($currentElement)) {
			$scope['state'] = 'error_element_move_access_denied';
			return \Response::json($scope);
		}

		$input = \Input::all();

		$site = \App::make('site');

		$currentItem = $site->getItemByName($currentElement->getClass());

		$propertyList = $currentItem->getPropertyList();

		foreach ($propertyList as $propertyName => $property) {
			if (
				$property->getHidden()
				|| ! $property->isOneToOne()
			) continue;

			if (isset($input[$propertyName])) {
				$currentElement->$propertyName = $input[$propertyName];
			}
		}

		try {
			$currentElement->save();
		} catch (\Exception $e) {
			$scope['state'] = 'error_element_save_failed';
			return \Response::json($scope);
		}

		\Cache::tags($currentElement->getClass())->flush();

		\Cache::forget("getByClassId({$currentElement->getClassId()})");

		\Cache::forget("getWithTrashedByClassId({$currentElement->getClassId()})");

		\Cache::forget("getOnlyTrashedByClassId({$currentElement->getClassId()})");

		UserAction::log(
			UserActionType::ACTION_TYPE_MOVE_ELEMENT_ID,
			$currentElement->getClassId()
		);

		$scope['state'] = 'ok';

		return \Response::json($scope);
	}

	public function delete($classId)
	{
		$scope = array();

		$currentElement = Element::getWithTrashedByClassId($classId);

		if ( ! $currentElement) {
			$scope['state'] = 'error_element_not_found';
			return \Response::json($scope);
		}

		$loggedUser = LoggedUser::getUser();

		if ( ! $loggedUser->hasDeleteAccess($currentElement)) {
			$scope['state'] = 'error_element_delete_access_denied';
			return \Response::json($scope);
		}

		if ($currentElement->trashed()) {

			Element::deleteFromTrash($currentElement);

			UserAction::log(
				UserActionType::ACTION_TYPE_DROP_ELEMENT_ID,
				$currentElement->getClassId()
			);

			$scope['state'] = 'ok';

		} elseif (Element::delete($currentElement)) {

			UserAction::log(
				UserActionType::ACTION_TYPE_DROP_ELEMENT_TO_TRASH_ID,
				$currentElement->getClassId()
			);

			$scope['state'] = 'ok';

		} else {

			$scope['state'] = 'error_element_delete_restricted';

		}

		return \Response::json($scope);
	}

	public function restore($classId)
	{
		$scope = array();

		$currentElement = Element::getOnlyTrashedByClassId($classId);

		if ( ! $currentElement) {
			$scope['state'] = 'error_element_not_found';
			return \Response::json($scope);
		}

		$loggedUser = LoggedUser::getUser();

		if ( ! $loggedUser->hasDeleteAccess($currentElement)) {
			$scope['state'] = 'error_element_restore_access_denied';
			return \Response::json($scope);
		}

		if ($currentElement->trashed()) {

			Element::restore($currentElement);

			UserAction::log(
				UserActionType::ACTION_TYPE_RESTORE_ELEMENT_ID,
				$currentElement->getClassId()
			);

			$scope['state'] = 'ok';

		}

		return \Response::json($scope);
	}

	public function save($classId)
	{
		$scope = array();

		$currentElement = Element::getWithTrashedByClassId($classId);

		if ( ! $currentElement) {
			$scope['state'] = 'error_element_not_found';
			return \Response::json($scope);
		}

		$loggedUser = LoggedUser::getUser();

		if ( ! $loggedUser->hasUpdateAccess($currentElement)) {
			$scope['state'] = 'error_element_update_access_denied';
			return \Response::json($scope);
		}

		$input = \Input::all();

		$site = \App::make('site');

		$currentItem = $site->getItemByName($currentElement->getClass());
		$mainProperty = $currentItem->getMainProperty();

		$propertyList = $currentItem->getPropertyList();

		$rules = array();
		$messages = array();
		$titles = array();

		foreach ($propertyList as $propertyName => $property) {
			if (
				$property->getHidden()
				|| $property->getReadonly()
			) continue;

			$titles[$propertyName] = $property->getTitle();

			foreach ($property->getRules() as $rule => $message) {
				$rules[$propertyName][] = $rule;
				if (strpos($rule, ':')) {
					list($name, $value) = explode(':', $rule, 2);
					$messages[$propertyName.'.'.$name] = $message;
				} else {
					$messages[$propertyName.'.'.$rule] = $message;
				}
			}
		}

		$validator = \Validator::make($input, $rules, $messages);

		if ($validator->fails()) {
			$messages = $validator->messages()->getMessages();
			$errors = array();

			foreach ($messages as $field => $messageList) {
				foreach ($messageList as $message) {
					$errors[$field][] = $message;
				}
			}

			$scope['error'] = $errors;

			return json_encode($scope);
		}

		foreach ($propertyList as $propertyName => $property) {
			if (
				$property->getHidden()
				|| $property->getReadonly()
				|| $property instanceof OrderProperty
			) continue;

			$property->setElement($currentElement)->set();
		}

		Element::save($currentElement);

		$currentElement->classId = $currentElement->getClassId();
		$currentElement->mainProperty = $currentElement->$mainProperty;
		$currentElement->trashed = $currentElement->trashed();
		$currentElement->href = method_exists($currentElement, 'getHref')
			? $currentElement->getHref() : null;

		$properties = [];
		$ones = [];

		foreach ($propertyList as $propertyName => $property) {
			if (
				$property->getHidden()
			) continue;

			if (
				! $currentElement->trashed()
				&& $propertyName == 'deleted_at'
			) continue;

			$property->setElement($currentElement);

			$properties[] = [
				'name' => $property->getName(),
				'title' => $property->getTitle(),
				'class' => $property->getClassName(),
				'readonly' => $property->getReadonly(),
				'isMainProperty' => $property->isMainProperty(),
				'element' => $property->getElement(),
				'item' => [
					'name' => $currentItem->getName(),
					'title' => $currentItem->getTitle(),
				],
				'editView' => $property->getEditView(),
			];

			if ($property->isOneToOne()) {
				$ones[] = [
					'name' => $property->getName(),
					'title' => $property->getTitle(),
					'class' => $property->getClassName(),
					'readonly' => $property->getReadonly(),
					'element' => $property->getElement(),
					'item' => [
						'name' => $currentItem->getName(),
						'title' => $currentItem->getTitle(),
					],
					'moveView' => $property->getMoveView(),
				];
			}
		}

		UserAction::log(
			UserActionType::ACTION_TYPE_SAVE_ELEMENT_ID,
			$currentElement->getClassId()
		);

		$scope['currentElement'] = $currentElement;
		$scope['propertyList'] = $properties;
		$scope['ones'] = $ones;
		$scope['state'] = 'ok';

		return \Response::json($scope);
	}

	public function create($class, $classId = null)
	{
		$scope = array();

		$loggedUser = LoggedUser::getUser();

		if ($classId) {
			$parentElement = Element::getByClassId($classId);

			if ( ! $parentElement) {
				$scope['state'] = 'error_element_not_found';
				return \Response::json($scope);
			}

			if ( ! $loggedUser->hasViewAccess($parentElement)) {
				$scope['state'] = 'error_element_access_denied';
				return \Response::json($scope);
			}
		} else {
			$parentElement = null;
		}

		$site = \App::make('site');

		$currentItem = $site->getItemByName($class);

		if ( ! $currentItem) {
			$scope['state'] = 'error_item_not_found';
			return \Response::json($scope);
		}

		$mainProperty = $currentItem->getMainProperty();

		$item = [
			'name' => $currentItem->getName(),
			'nameId' => $currentItem->getNameId(),
			'title' => $currentItem->getTitle(),
		];

		$currentElement = new $currentItem->getClass();

		$currentElement->classId = null;
		$currentElement->mainProperty = null;
		$currentElement->trashed = false;
		$currentElement->href = null;

		if ($parentElement instanceof ElementInterface) {
			$currentElement->setParent($parentElement);

			$parentList = Element::getParentList($currentElement);

			$parentElement->classId = $parentElement->getClassId();
			$parentElement->mainProperty = $parentElement->$mainProperty;

			foreach ($parentList as $parent) {
				$parent->classId = $parent->getClassId();
				$parent->mainProperty = $parent->$mainProperty;
			}
		}

		$propertyList = $currentItem->getPropertyList();

		$properties = [];
		$ones = [];

		foreach ($propertyList as $propertyName => $property) {
			if (
				$property->getHidden()
			) continue;

			if (
				$propertyName == 'deleted_at'
			) continue;

			$property->setElement($currentElement);

			$properties[] = [
				'name' => $property->getName(),
				'title' => $property->getTitle(),
				'class' => $property->getClassName(),
				'readonly' => $property->getReadonly(),
				'isMainProperty' => $property->isMainProperty(),
				'element' => $property->getElement(),
				'item' => [
					'name' => $currentItem->getName(),
					'title' => $currentItem->getTitle(),
				],
				'editView' => $property->getEditView(),
			];

			if ($property->isOneToOne()) {
				$ones[] = [
					'name' => $property->getName(),
					'title' => $property->getTitle(),
					'class' => $property->getClassName(),
					'readonly' => $property->getReadonly(),
					'element' => $property->getElement(),
					'item' => [
						'name' => $currentItem->getName(),
						'title' => $currentItem->getTitle(),
					],
					'moveView' => $property->getMoveView(),
				];
			}
		}

		$scope['currentElement'] = $currentElement;
		$scope['parentElement'] = $parentElement;
		$scope['parentList'] = $parentList;
		$scope['currentItem'] = $item;
		$scope['propertyList'] = $properties;
		$scope['ones'] = $ones;

		return \Response::json($scope);
	}

	public function edit($classId)
	{
		$scope = array();

		$currentElement = Element::getWithTrashedByClassId($classId);

		if ( ! $currentElement) {
			$scope['state'] = 'error_element_not_found';
			return \Response::json($scope);
		}

		$loggedUser = LoggedUser::getUser();

		if ( ! $loggedUser->hasViewAccess($currentElement)) {
			$scope['state'] = 'error_element_access_denied';
			return \Response::json($scope);
		}

		$site = \App::make('site');

		$currentItem = $site->getItemByName($currentElement->getClass());
		$mainProperty = $currentItem->getMainProperty();

		$item = [
			'name' => $currentItem->getName(),
			'nameId' => $currentItem->getNameId(),
			'title' => $currentItem->getTitle(),
		];

		$currentElement->classId = $currentElement->getClassId();
		$currentElement->mainProperty = $currentElement->$mainProperty;
		$currentElement->trashed = $currentElement->trashed();
		$currentElement->href = method_exists($currentElement, 'getHref')
			? $currentElement->getHref() : null;

		$parentElement = Element::getParent($currentElement);

		if ($parentElement) {
			$parentElement->classId = $parentElement->getClassId();
		}

		$parentList = Element::getParentList($currentElement);

		foreach ($parentList as $parent) {
			$parent->classId = $parent->getClassId();
			$parent->mainProperty = $parent->$mainProperty;
		}

		$propertyList = $currentItem->getPropertyList();

		$properties = [];
		$ones = [];

		foreach ($propertyList as $propertyName => $property) {
			if (
				$property->getHidden()
			) continue;

			if (
				! $currentElement->trashed()
				&& $propertyName == 'deleted_at'
			) continue;

			$property->setElement($currentElement);

			$properties[] = [
				'name' => $property->getName(),
				'title' => $property->getTitle(),
				'class' => $property->getClassName(),
				'readonly' => $property->getReadonly(),
				'isMainProperty' => $property->isMainProperty(),
				'element' => $property->getElement(),
				'item' => [
					'name' => $currentItem->getName(),
					'title' => $currentItem->getTitle(),
				],
				'editView' => $property->getEditView(),
			];

			if ($property->isOneToOne()) {
				$ones[] = [
					'name' => $property->getName(),
					'title' => $property->getTitle(),
					'class' => $property->getClassName(),
					'readonly' => $property->getReadonly(),
					'element' => $property->getElement(),
					'item' => [
						'name' => $currentItem->getName(),
						'title' => $currentItem->getTitle(),
					],
					'moveView' => $property->getMoveView(),
				];
			}
		}

		$scope['currentElement'] = $currentElement;
		$scope['parentElement'] = $parentElement;
		$scope['parentList'] = $parentList;
		$scope['currentItem'] = $item;
		$scope['propertyList'] = $properties;
		$scope['ones'] = $ones;

		return \Response::json($scope);
	}

}
