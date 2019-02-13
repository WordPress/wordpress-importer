/**
 * External dependencies
 */
import React, { Fragment, PureComponent } from 'react';
import { withRouter } from 'react-router'
import { Button, DropZoneProvider, DropZone } from '@wordpress/components';

class FileSelection extends PureComponent {
	state = {
		hasDropped: false,
	};

	setDropped = () => {
		this.setState( { hasDropped: true } );
	};

	nextStep = () => {
		this.props.history.push( '/map' );
	};

	render() {
		const { setState, state } = this;
		const { hasDropped } = state;

		return (
			<Fragment>
				<DropZoneProvider>
					<div>
						{ hasDropped ? 'Dropped!' : 'Drop something here' }
						<DropZone 
							onFilesDrop={ this.setDropped }
							onHTMLDrop={ this.setDropped }
							onDrop={ this.setDropped }
						/>
					</div>
				</DropZoneProvider>
				<Button onClick={ this.nextStep }>Continue</Button>
			</Fragment>
		);
	}
}

export default withRouter( FileSelection );
