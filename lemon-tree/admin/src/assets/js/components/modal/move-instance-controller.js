modal.controller('MoveInstanceController', function(
	$rootScope, $scope, $modalInstance, $http, $state, data
) {
	$scope.data = data;

	$scope.ok = function () {
		var classId = data.classId;
		var ones = data.ones;
		var reload = data.reload || false;
		var fields = {};

		for (var i in ones) {
			var property = ones[i];
			fields[property.name] = property.moveView.value
				? property.moveView.value.id : null;
		}

		$.blockUI();

		$http({
			method: 'POST',
			url: 'api/move/'+classId,
			data: fields
		}).then(
			function(response) {
				if (response.data.state == 'ok') {
					if (reload) {
						$state.reload();
					}
					$rootScope.refreshTree();
				}
				$modalInstance.close();
				$.unblockUI();
			},
			function(error) {
				console.log(error);
				$modalInstance.close();
				$.unblockUI();
			}
		);
	};

	$scope.cancel = function () {
		$modalInstance.dismiss();
	};
});