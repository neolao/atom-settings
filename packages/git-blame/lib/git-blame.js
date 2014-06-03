const Blamer = require('./util/blamer');
const BlameViewController = require('./controllers/blameViewController');

// reference to the Blamer instance created in initializeContext if this
// project is backed by a git repository.
var projectBlamer = null;

function activate() {
  initializeContext();

  // git-blame:blame
  atom.workspaceView.command('git-blame:toggle', function() {
    return toggleBlame();
  });

  return;
}

function initializeContext() {
  var projectRepo = atom.project.getRepo();

  // Ensure this project is backed by a git repository
  if (!projectRepo) {
    // TODO visually alert user
    return console.error('Cant initialize blame! there is no git repo for this project');
  }

  projectBlamer = new Blamer(projectRepo);
}

function toggleBlame() {
  // Nothing to do if projectBlamer isnt defined. Means this project is not
  // backed by git.
  if (!projectBlamer) {
    return;
  }

  var editor = atom.workspace.activePaneItem;
  var filePath = editor.getPath();

  BlameViewController.toggleBlame(filePath, projectBlamer);
}

// EXPORTS
module.exports = {
  configDefaults: {
    useCustomUrlTemplateIfStandardRemotesFail: false,
    customCommitUrlTemplateString: 'Example -> https://github.com/<%- project %>/<%- repo %>/commit/<%- revision %>'
  },
  toggleBlame: toggleBlame,
  activate: activate
};
