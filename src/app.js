/**
 * External dependencies
 */
import React, { PureComponent } from 'react';
import { HashRouter as Router, Route, Switch } from "react-router-dom";

/**
 * Internal dependencies
 */
import FileSelection from './file-selection';
import AuthorMapping from './author-mapping';

class App extends PureComponent {
	render() {
		return (
			<Router>
				<Switch>
					<Route exact path="/" component={ FileSelection } />
					<Route path="/map" component={ AuthorMapping } />
				</Switch>
			</Router>
		);
	}
}

export default App;
