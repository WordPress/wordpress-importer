/**
 * External dependencies
 */
import React, { PureComponent } from 'react';
import { HashRouter as Router, Route, Switch } from 'react-router-dom';
import { registerStore } from '@wordpress/data';

/**
 * Internal dependencies
 */
import FileSelection from './file-selection';
import AuthorMapping from './author-mapping';
import ImportComplete from './import-complete';

const DEFAULT_STATE = {
	attachmentId: null,
};

registerStore( 'wordpress-importer', {
	reducer( state = DEFAULT_STATE, action ) {
		switch ( action.type ) {
			case 'SET_ATTACHMENT_ID':
				return {
					...state,
					attachmentId: action.attachmentId
				};
		}
		return state;
	},

	actions: {
		setAttachmentId( attachmentId ) {
			return {
				type: 'SET_ATTACHMENT_ID',
				attachmentId,
			};
		}
	},

	selectors: {
		getAttachmentId( state ) {
			return state.attachmentId
		}
	}
} );

class App extends PureComponent {
	render() {
		return (
			<Router>
				<Switch>
					<Route exact path="/" component={ FileSelection } />
					<Route path="/map" component={ AuthorMapping } />
					<Route path="/complete" component={ ImportComplete } />
				</Switch>
			</Router>
		);
	}
}

export default App;
