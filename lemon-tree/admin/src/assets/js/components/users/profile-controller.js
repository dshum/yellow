users.controller('ProfileController', function(
	$rootScope, $scope, $http
) {
	$rootScope.activeIcon = 'profile';

	$scope.$on('loggedUser', function (event, loggedUser) {
		$scope.profile = loggedUser;
	});

	$scope.submit = function() {
		console.log($scope.profile);

		$http({
			method: 'POST',
			url: 'api/profile',
			data: $scope.profile,
			checkForm: true
		});
	};
});